<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\State\Adapter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
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
     * @var string
     */
    private $configurationRowId;

    /**
     * @var array
     */
    private $state;

    /**
     * @var string
     */
    private $format;

    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;

    public function __construct(
        $dataDirectory,
        Client $storageClient,
        array $state,
        $format,
        $componentId,
        $configurationId,
        OutputFilterInterface $outputFilter,
        $configurationRowId = null
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->storageClient = $storageClient;
        $this->componentId = $componentId;
        $this->configurationId = $configurationId;
        $this->configurationRowId = $configurationRowId;
        $this->state = $state;
        $this->format = $format;
        $this->outputFilter = $outputFilter;
        $this->outputFilter->collectValues($state);
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

    public function storeState($currentState)
    {
        $previousState = $this->state;
        // Store state
        if (!$previousState) {
            $previousState = new \stdClass();
        }

        $this->outputFilter->collectValues((array)$currentState);
        if (serialize($currentState) != serialize($previousState)) {
            $components = new Components($this->storageClient);
            $configuration = new Configuration();
            $configuration->setComponentId($this->componentId);
            $configuration->setConfigurationId($this->configurationId);
            if ($this->configurationRowId) {
                $configurationRow = new ConfigurationRow($configuration);
                $configurationRow->setRowId($this->configurationRowId);
                $configurationRow->setState($currentState);
                $components->updateConfigurationRow($configurationRow);
            } else {
                $configuration->setState($currentState);
                $components->updateConfiguration($configuration);
            }
        }
    }

    public function loadStateFromFile()
    {
        $stateAdapter = new Adapter($this->format);
        $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state'
            . $stateAdapter->getFileExtension();
        $fs = new Filesystem();
        if ($fs->exists($fileName)) {
            $state = $stateAdapter->readFromFile($fileName);
        } else {
            $state = [];
        }
        $fs->remove($fileName);
        return $state;
    }
}
