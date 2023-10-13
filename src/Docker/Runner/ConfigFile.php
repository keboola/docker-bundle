<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Exception\UserException;
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
     * @var Authorization
     */
    private $authorization;

    /**
     * @var string
     */
    private $action;

    public function __construct(
        $dataDirectory,
        Authorization $authorization,
        $action,
        $format,
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->format = $format;
        $this->authorization = $authorization;
        $this->action = $action;
    }

    public function createConfigFile(
        $configData,
        OutputFilterInterface $outputFilter,
        array $workspaceCredentials,
        array $imageParameters,
    ) {
        // create configuration file injected into docker
        $adapter = new Adapter($this->format);
        try {
            $backendContext = $configData['runtime']['backend']['context'] ?? null;
            // remove runtime parameters and processors which are not supposed to be passed into the container
            unset($configData['runtime']);
            unset($configData['processors']);

            $configData['image_parameters'] = $imageParameters;
            if (!empty($configData['authorization'])) {
                $configData['authorization'] = $this->authorization->getAuthorization($configData['authorization']);
            } else {
                unset($configData['authorization']);
            }
            if (empty($configData['storage'])) {
                unset($configData['storage']);
            }
            if ($workspaceCredentials) {
                $configData['authorization']['workspace'] = $workspaceCredentials;
            }
            if ($backendContext) {
                $configData['authorization']['context'] = $backendContext;
            }

            // action
            $configData['action'] = $this->action;

            $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . 'config' . $adapter->getFileExtension();
            $outputFilter->collectValues($configData);
            $adapter->setConfig($configData);
            $adapter->writeToFile($fileName);
        } catch (InvalidConfigurationException $e) {
            throw new UserException('Error in configuration: ' . $e->getMessage(), $e);
        }
    }
}
