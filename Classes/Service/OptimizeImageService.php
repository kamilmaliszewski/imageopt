<?php

/***************************************************************
 *  Copyright notice
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace SourceBroker\Imageopt\Service;

use SourceBroker\Imageopt\Configuration\Configurator;
use SourceBroker\Imageopt\Domain\Model\ModeResult;
use SourceBroker\Imageopt\Domain\Model\StepResult;
use SourceBroker\Imageopt\Provider\OptimizationProvider;
use SourceBroker\Imageopt\Utility\TemporaryFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Optimize single image using multiple Image Optmization Provider.
 * The best optimization wins!
 */
class OptimizeImageService
{
    /**
     * @var object|Configurator
     */
    public $configurator;

    /**
     * @var TemporaryFileUtility
     */
    private $temporaryFile;

    /**
     * OptimizeImageService constructor.
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = null)
    {
        if ($config === null) {
            throw new \Exception('Configuration not set for OptimizeImageService class');
        }

        $this->configurator = GeneralUtility::makeInstance(Configurator::class, $config);
        $this->configurator->init();

        $this->temporaryFile = GeneralUtility::makeInstance(TemporaryFileUtility::class);
    }

    /**
     * Optimize image using chained Image Optimization Provider
     *
     * @param string $originalImagePath
     * @return ModeResult[]
     * @throws \Exception
     */
    public function optimize($originalImagePath)
    {
        if (!file_exists($originalImagePath) || !filesize($originalImagePath)) {
            throw new \Exception('Can not read file to optimize. File: "' . $originalImagePath . '"');
        }
        // create original image copy - it may vary (provider may overwrite original image)
        $sourceImagePath = $this->temporaryFile->createTemporaryCopy($originalImagePath);

        $modeResults = [];
        foreach ((array)$this->configurator->getOption('mode') as $modeKey => $modeConfig) {
            $regexp = '@' . $modeConfig['fileRegexp'] . '@';
            $modeConfig['name'] = $modeKey;
            if (!preg_match($regexp, $originalImagePath)) {
                continue;
            }
            $modeResults[$modeKey] = $this->optimizeSingleMode(
                $modeConfig,
                $sourceImagePath,
                $originalImagePath
            );
        }

        return $modeResults;
    }

    /**
     * @param array $modeConfig
     * @param string $sourceImagePath Path to original image COPY (default optimization mode will overwrite original image)
     * @param string $originalImagePath Path to original image
     * @return ModeResult
     * @throws \Exception
     */
    protected function optimizeSingleMode($modeConfig, $sourceImagePath, $originalImagePath)
    {
        $modeResult = GeneralUtility::makeInstance(ModeResult::class)
            ->setFileAbsolutePath($originalImagePath)
            ->setName($modeConfig['name'])
            ->setDescription($modeConfig['description'])
            ->setSizeBefore(filesize($sourceImagePath))
            ->setExecutedSuccessfully(false);

        $chainImagePath = $this->temporaryFile->createTemporaryCopy($sourceImagePath);

        foreach ($modeConfig['step'] as $stepKey => $stepConfig) {
            $stepResult = GeneralUtility::makeInstance(StepResult::class)
                ->setExecutedSuccessfully(false)
                ->setSizeBefore(filesize($chainImagePath))
                ->setSizeAfter(filesize($chainImagePath))
                ->setName($stepKey)
                ->setDescription(!empty($stepConfig['description']) ? $stepConfig['description'] : $stepKey);

            $providers = $this->configurator->getProviders(
                $stepConfig['providerType'],
                strtolower(explode('/', image_type_to_mime_type(getimagesize($originalImagePath)[2]))[1])
            );
            if (empty($providers)) {
                // skip this step - no providers for this type of image
                $stepResult->setInfo('No providers found for "' . $stepConfig['providerType'] . '"');
                continue;
            }
            $this->optimizeWithBestProvider($stepResult, $chainImagePath, $providers);
            $modeResult->addStepResult($stepResult);
        }
        if ($modeResult->getExecutedSuccessfullyNum() == $modeResult->getStepResults()->count()) {
            $modeResult->setExecutedSuccessfully(true);
        }

        clearstatcache(true, $chainImagePath);
        $modeResult->setSizeAfter(filesize($chainImagePath));

        $pathInfo = pathinfo($originalImagePath);
        copy($chainImagePath, str_replace(
            ['{dirname}', '{basename}', '{extension}', '{filename}'],
            [$pathInfo['dirname'], $pathInfo['basename'], $pathInfo['extension'], $pathInfo['filename']],
            $modeConfig['outputFilename']
        ));

        return $modeResult;
    }

    /**
     * @param $stepResult
     * @param string $chainImagePath
     * @param array $providers
     * @return StepResult
     * @throws \Exception
     */
    protected function optimizeWithBestProvider($stepResult, $chainImagePath, $providers)
    {
        clearstatcache(true, $chainImagePath);

        $providerExecutedCounter = 0;
        $providerExecutedSuccessfullyCounter = 0;
        $providerEnabledCounter = 0;

        // work on chain image copy
        $tmpBestImagePath = $this->temporaryFile->createTemporaryCopy($chainImagePath);

        foreach ($providers as $providerKey => $providerConfig) {
            $providerConfig['providerKey'] = $providerKey;
            $providerConfigurator = GeneralUtility::makeInstance(Configurator::class, $providerConfig);

            if (empty($providerConfigurator->getOption('enabled'))) {
                continue;
            }

            $providerEnabledCounter++;
            $providerExecutedCounter++;

            $tmpWorkingImagePath = $this->temporaryFile->createTemporaryCopy($chainImagePath);
            $optimizationProvider = GeneralUtility::makeInstance(OptimizationProvider::class);

            $providerResult = $optimizationProvider->optimize($tmpWorkingImagePath, $providerConfigurator);

            if ($providerResult->isExecutedSuccessfully()) {
                $providerExecutedSuccessfullyCounter++;
                clearstatcache(true, $tmpWorkingImagePath);

                if (filesize($tmpWorkingImagePath) < filesize($tmpBestImagePath)) {
                    // overwrite current (in chain link) best image
                    $tmpBestImagePath = $tmpWorkingImagePath;
                    $stepResult->setProviderWinnerName($providerKey);
                    $stepResult->setSizeAfter(filesize($tmpBestImagePath));
                }
            }
            $stepResult->addProvidersResult($providerResult);
        }

        if ($providerEnabledCounter === 0) {
            $stepResult->setInfo('No providers enabled (or defined).');
        } elseif ($providerExecutedSuccessfullyCounter === 0) {
            $stepResult->setInfo('No winner. All providers in this step were unsuccessfull.');
        } else {
            $stepResult->setExecutedSuccessfully(true);
            if ($stepResult->getOptimizationBytes() === 0) {
                $stepResult->setInfo('No winner of this step. Non of the optimized images were smaller than original.');
            } else {
                if ($stepResult->getProviderWinnerName()) {
                    $stepResult->setInfo('Winner is ' . $stepResult->getProviderWinnerName() .
                        ' with optimized image smaller by: ' .
                        round($stepResult->getOptimizationPercentage(), 2) . '%');
                }
                clearstatcache(true, $tmpBestImagePath);
                // overwrite chain image with current best image
                copy($tmpBestImagePath, $chainImagePath);
            }
        }
        clearstatcache(true, $chainImagePath);
    }
}
