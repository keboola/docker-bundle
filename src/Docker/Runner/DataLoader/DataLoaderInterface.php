<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

interface DataLoaderInterface
{
    public function __construct(Client $storageClient, LoggerInterface $logger, $dataDirectory, array $storageConfig, Component $component, OutputFilterInterface $outputFilter, $configId = null, $configRowId = null);

    public function loadInputData();

    public function storeOutput();

    public function storeDataArchive($fileName, array $tags);
}