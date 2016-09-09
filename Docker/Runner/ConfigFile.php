<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigFile
{
    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var string
     */
    private $format;

    /**
     * @var array
     */
    private $imageParameters;

    /**
     * @var Authorization
     */
    private $authorization;

    /**
     * @var string
     */
    private $action;

    public function __construct(
        $dataDirectory,
        array $imageParameters,
        Authorization $authorization,
        $action,
        $format
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->format = $format;
        $this->imageParameters = $imageParameters;
        $this->authorization = $authorization;
        $this->action = $action;
    }

    public function createConfigFile($configData)
    {
        // create configuration file injected into docker
        $adapter = new Adapter($this->format);
        try {
            // remove runtime parameters which is not supposed to be passed into the container
            unset($configData['runtime']);

            $configData['image_parameters'] = $this->imageParameters;
            if (!empty($configData['authorization'])) {
                $configData['authorization'] = $this->authorization->getAuthorization($configData['authorization']);
            } else {
                $configData['authorization'] = [];
            }

            // action
            $configData['action'] = $this->action;

            $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . 'config' . $adapter->getFileExtension();
            $adapter->setConfig($configData);
            $adapter->writeToFile($fileName);
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Error in configuration: " . $e->getMessage(), $e);
        }
    }
}
