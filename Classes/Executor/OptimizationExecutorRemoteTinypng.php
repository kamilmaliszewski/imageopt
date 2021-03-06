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

namespace SourceBroker\Imageopt\Executor;

use SourceBroker\Imageopt\Configuration\Configurator;
use SourceBroker\Imageopt\Domain\Model\ExecutorResult;

class OptimizationExecutorRemoteTinypng extends OptimizationExecutorRemote
{

    /**
     * Initialize executor
     *
     * @param Configurator $configurator
     * @return bool
     */
    protected function initConfiguration(Configurator $configurator)
    {
        $result = parent::initConfiguration($configurator);
        if ($result) {
            if (!isset($this->auth['key'])) {
                $result = false;
            } elseif (!isset($this->url['upload'])) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * Upload file to tinypng.com and save it if optimization will be success
     *
     * @param string $inputImageAbsolutePath Absolute path/file with original image
     * @param ExecutorResult $executorResult
     */
    protected function process($inputImageAbsolutePath, ExecutorResult $executorResult)
    {
        $executorResult->setCommand('URL: ' . $this->url['upload']);
        $result = $this->request(file_get_contents($inputImageAbsolutePath), $this->url['upload']);
        if ($result['success']) {
            if (isset($result['response']['output']['url'])) {
                $download = $this->getFileFromRemoteServer($inputImageAbsolutePath,
                    $result['response']['output']['url']);

                if ($download) {
                    $executorResult->setExecutedSuccessfully(true);
                    $executorResult->setCommandStatus('Done');
                } else {
                    $executorResult->setErrorMessage('Unable to download image');
                    $executorResult->setCommandStatus('Failed');
                }
            } else {
                $executorResult->setErrorMessage('Download URL not defined');
                $executorResult->setCommandStatus('Failed');
            }
        } else {
            $executorResult->setErrorMessage($result['error']);
            $executorResult->setCommandStatus('Failed');
        }
    }

    /**
     * Request to tinypng.com using CURL
     *
     * @param string $data String from image file
     * @param string $url API tinypng.com url
     * @param array $params Additional parameters
     * @return array Result of optimization includes the response from the tinypng.com
     */
    protected function request($data, $url, array $params = [])
    {
        $options = array_merge([
            'curl' => [
                CURLOPT_HEADER => true,
                CURLOPT_USERPWD => 'api:' . $this->auth['key'],
            ],
        ], $params);

        $responseFromAPI = parent::request($data, $url, $options);

        $handledResponse = $this->handleResponseError($responseFromAPI);
        if ($handledResponse !== null) {
            return [
                'success' => false,
                'error' => $handledResponse
            ];
        }

        $body = substr($responseFromAPI['response'], $responseFromAPI['header_size']);
        return [
            'success' => true,
            'response' => json_decode($body, true),
        ];
    }
}
