<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\State\Adapter;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;

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
        $writer = new Writer($this->storageClient);

        $writer->updateState(
            $this->componentId,
            $this->configurationId,
            $this->dataDirectory . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state',
            $previousState
        );
    }
}
