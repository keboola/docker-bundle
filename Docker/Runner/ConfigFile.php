<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\OAuthV2Api\Exception\RequestException;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigFile
{
    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var array
     */
    private $config;

    /**
     * @var string
     */
    private $format;

    /**
     * @var array
     */
    private $imageParameters;

    /**
     * @var array
     */
    private $authorization;

    /**
     * @var string
     */
    private $action;

    public function __construct(
        $dataDirectory,
        array $config,
        array $imageParameters,
        array $authorization,
        $action,
        $format
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->config = $config;
        $this->format = $format;
        $this->imageParameters = $imageParameters;
        $this->authorization = $authorization;
        $this->action = $action;
    }

    public function createConfigFile()
    {
        // create configuration file injected into docker
        $adapter = new Adapter($this->format);
        try {
            $configData = $this->config;
            // remove runtime parameters which is not supposed to be passed into the container
            unset($configData['runtime']);

            $configData['image_parameters'] = $this->imageParameters;
            $configData["authorization"] = $this->authorization;

            // action
            $configData["action"] = $this->action;

            $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . "config" . $adapter->getFileExtension();
            $adapter->setConfig($configData);
            $adapter->writeToFile($fileName);
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Error in configuration: " . $e->getMessage(), $e);
        } catch (RequestException $e) {
            throw new UserException("Error loading credentials: " . $e->getMessage(), $e);
        }
    }
}
