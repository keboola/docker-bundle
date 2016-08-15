<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\State\Adapter;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Symfony\Component\Filesystem\Filesystem;

class StateFile
{
    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var Client
     */
    private $storageClient;

    /**
     * @var string
     */
    private $componentId;

    /**
     * @var string
     */
    private $configurationId;

    /**
     * @var array
     */
    private $state;

    /**
     * @var string
     */
    private $format;

    public function __construct(
        $dataDirectory,
        Client $storageClient,
        array $state,
        $componentId,
        $configurationId,
        $format
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->storageClient = $storageClient;
        $this->componentId = $componentId;
        $this->configurationId = $configurationId;
        $this->state = $state;
        $this->format = $format;
    }

    public function createStateFile()
    {
        // Store state
        $stateAdapter = new Adapter($this->format);
        $stateAdapter->setConfig($this->state);
        $stateFileName = $this->dataDirectory. DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR .
            'state' . $stateAdapter->getFileExtension();
        $stateAdapter->writeToFile($stateFileName);
    }

    public function storeStateFile()
    {
        $previousState = $this->state;
        // Store state
        if (!$previousState) {
            $previousState = new \stdClass();
        }

        $stateAdapter = new Adapter($this->format);
        $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state'
            . $stateAdapter->getFileExtension();
        $fs = new Filesystem();
        if ($fs->exists($fileName)) {
            $currentState = $stateAdapter->readFromFile($fileName);
        } else {
            $currentState = [];
        }
        if (serialize($currentState) != serialize($previousState)) {
            $components = new Components($this->storageClient);
            $configuration = new Configuration();
            $configuration->setComponentId($this->componentId);
            $configuration->setConfigurationId($this->configurationId);
            $configuration->setState($currentState);
            $components->updateConfiguration($configuration);
        }
    }
}
