<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

interface DataLoaderInterface
{
    public function __construct(Client $storageClient, LoggerInterface $logger, $dataDirectory, array $storageConfig, Component $component, OutputFilterInterface $outputFilter, $configId = null, $configRowId = null);

    /**
     * @return InputTableStateList
     */
    public function loadInputData();

    /**
     * @return LoadTableQueue|null
     */
    public function storeOutput();

    public function storeDataArchive($fileName, array $tags);
}
