<?php

namespace SourceBroker\Imageopt\Utility;

use SourceBroker\Imageopt\Domain\Model\ExecutorResult;
use SourceBroker\Imageopt\Domain\Model\OptionResult;
use SourceBroker\Imageopt\Domain\Model\ProviderResult;
use SourceBroker\Imageopt\Domain\Model\StepResult;

class CliDisplayUtility
{

    /**
     * Displays optimization result in CLI window
     *
     * @param OptionResult $optionResult
     * @return string
     */
    public static function displayOptionResult(OptionResult $optionResult, $config)
    {
        $stepProvidersInfo = [];
        /** @var StepResult[] $stepResults */
        $stepResults = $optionResult->getStepResults()->toArray();

        foreach ($stepResults as $stepKey => $stepResult) {
            $providers = [];
            $providersScore = [];

            /** @var ProviderResult[] $providerResults */
            $providerResults = $stepResult->getProvidersResults();
            foreach ($providerResults as $providerResult) {
                if ($providerResult->isExecutedSuccessfully()) {
                    $providers[] = $providerResult->getName() . ': ' . round($providerResult->getOptimizationPercentage(),
                            2) . '%';
                    $providersScore[] = $providerResult->getOptimizationPercentage();
                } else {
                    /** @var ExecutorResult $executorResult */
                    $error = [];
                    foreach ($providerResult->getExecutorsResults()->toArray() as $executorResult) {
                        if (!$executorResult->isExecutedSuccessfully()) {
                            $error[] = $executorResult->getErrorMessage();
                        }
                    }

                    $limit = 49 - strlen($providerResult->getName());
                    $errors = implode(', ', $error);
                    if (strlen($errors) > $limit) {
                        $errors = substr($errors, 0, $limit - 2) . '..';
                    }

                    $providers[] = $providerResult->getName() . ' - failed: ' . $errors;
                    $providersScore[] = null;
                }
            }

            uksort($providers, function ($a, $b) use ($providersScore) {
                if ($a === null) {
                    return 1;
                } elseif ($b === null) {
                    return -1;
                }
                return $providersScore[$a] > $providersScore[$b] ? -1 : 1;
            });

            $providers = array_values($providers);

            foreach ($providers as $i => &$provider) {
                $provider = '* ' . $provider;
            }

            $providerType = $config['mode'][$optionResult->getName()]['step'][$stepResult->getName()]['providerType'];
            $stepResultFinal = ($stepResult->getProvidersResults()->count() > 0 &&
            $stepResult->getProvidersResults()->count() == $stepResult->getExecutedSuccessfullyNum() ? 'OK' : 'FAIL');

            $fileType = strtolower(explode('/',
                image_type_to_mime_type(getimagesize($optionResult->getFileRelativePath())[2]))[1]);

            $statsInfo = 'Step ' . ($stepKey + 1) . "\t\t| Description: " . $stepResult->getDescription() . "\n"
            . "\t\t| Providers to find for this step: \"" . $providerType . "\" for file type \"" . $fileType . "\"\n"
            . "\t\t| Found " . $stepResult->getProvidersResults()->count() . " provider" . ($stepResult->getProvidersResults()->count() > 1 ? 's' : '')
                . " for \"" . $providerType . "\" and file type \"" . $fileType . "\". ";

            if (!empty($providers)) {
                $statsInfo .= "Running found providers:\n"
                    . "\t\t| " . implode("\n\t\t| ", $providers);
            }
            $statsInfo .= "\n\t\t| Result: " . $stepResultFinal . "\n"
                . "\t\t| " . $stepResult->getInfo() . "\n";
            if($stepKey < $optionResult->getStepResults()->count() ) {
                $statsInfo .= "\t\t| Passing the output file of this step to next step...";
            }
            $stepProvidersInfo[] = $statsInfo;
        }
        $pathInfo = pathinfo($optionResult->getFileRelativePath());
        $outputFile = str_replace(
            ['{dirname}', '{basename}', '{extension}', '{filename}'],
            [$pathInfo['dirname'], $pathInfo['basename'], $pathInfo['extension'], $pathInfo['filename']],
            $config['mode'][$optionResult->getName()]['outputFilename']
        );

        $output = '---------------------------------------------------------------------' . "\n" .
            "File in\t\t| " . $optionResult->getFileRelativePath() . "\n" .
            "File out\t| " . $outputFile . "\n" .
            "Mode\t\t| Name: " . $optionResult->getName() . "\n" .
            "\t\t| Description: " . $optionResult->getDescription() . "\n" .
            "\t\t| Number of steps: " . $optionResult->getStepResults()->count() . "\n";

        if (strlen($optionResult->getInfo())) {
            $output .= implode("\t\t| ", explode("\n", wordwrap($optionResult->getInfo(), 100))) . "\n";
        }
        if (!empty($stepProvidersInfo)) {
            $output .= implode("\n", $stepProvidersInfo);
        }
        $output .= "\n\n" . "Result\t\t| ";
        if ($optionResult->isExecutedSuccessfully()) {
            $output .= 'OK ' . round($optionResult->getOptimizationPercentage(), 2) . '%';
        } else {
            $output .= 'FAIL ';
        }
        return $output . "\n\n";
    }
}
