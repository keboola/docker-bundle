<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\DockerBundle\Service\AuthorizationService;
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
     * @var AuthorizationService
     */
    private $authorizationService;

    /**
     * @var string
     */
    private $action;

    /**
     * @var string
     */
    private $componentId;

    /**
     * @var bool
     */
    private $sandboxed;

    public function __construct(
        $dataDirectory,
        array $imageParameters,
        AuthorizationService $authorization,
        $action,
        Component $component,
        $sandboxed
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->format = $component->getConfigurationFormat();
        $this->componentId = $component->getId();
        $this->imageParameters = $imageParameters;
        $this->authorizationService = $authorization;
        $this->action = $action;
        $this->sandboxed = $sandboxed;
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
                $configData['authorization'] = $this->authorizationService->getAuthorization(
                    $configData['authorization'],
                    $this->componentId,
                    $this->sandboxed
                );
            } else {
                unset($configData['authorization']);
            }
            if (empty($configData['storage'])) {
                unset($configData['storage']);
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
