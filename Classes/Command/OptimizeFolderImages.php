<?php

namespace SourceBroker\Imageopt\Command;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use SourceBroker\Imageopt\Service\OptimizeImagesFolderService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class ImageoptCommandController
 */
class OptimizeFolderImages extends BaseCommand
{
    /**
     * Configure the command by defining the name, options and arguments
     */
    public function configure()
    {
        $this->setDescription('Optimize images in folders')
            ->addArgument(
                'numberOfImagesToProcess',
                InputArgument::OPTIONAL,
                'The number of images to process on single task call.'
            )
            ->addArgument(
                'rootPageForTsConfig',
                InputArgument::OPTIONAL,
                'The page uid for which the TSconfig is parsed. If not set then first found root page will be used.'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \InvalidArgumentException
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $numberOfImagesToProcess = $input->hasArgument('numberOfImagesToProcess') && $input->getArgument('numberOfImagesToProcess') !== null ? $input->getArgument('numberOfImagesToProcess') : 20;
        $rootPageForTsConfig = $input->hasArgument('rootPageForTsConfig') && $input->getArgument('rootPageForTsConfig') !== null ? $input->getArgument('rootPageForTsConfig') : null;

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->initConfigurator($rootPageForTsConfig);
        $optimizeImagesFolderService = $objectManager->get(
            OptimizeImagesFolderService::class,
            $this->getConfigurator()->getConfig()
        );
        $filesToProcess = $optimizeImagesFolderService->getFilesToOptimize($numberOfImagesToProcess);
        if (!empty($filesToProcess)) {
            foreach ($filesToProcess as $fileToProcess) {
                $optimizationResult = $optimizeImagesFolderService->optimizeFolderFile($fileToProcess);
                $io->write($this->showResult($optimizationResult));
            }
        } else {
            if (!$io->isQuiet()) {
                $output->writeln('No images found that can be optimized.');
            }
        }
        return 0;
    }
}
