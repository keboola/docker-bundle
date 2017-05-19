<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

interface DataLoaderInterface
{
    public function __construct(Client $storageClient, LoggerInterface $logger, $dataDirectory, array $storageConfig, Component $component, $configId = null);

    public function loadInputData();

    public function storeOutput();

    public function storeDataArchive(array $tags);

    public function setFeatures($features);
}
