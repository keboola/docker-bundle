<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\StorageApi\Client;
use Monolog\Logger;

class NullDataLoader implements \Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface
{
    public function __construct(Client $storageClient, Logger $logger, $dataDirectory, array $storageConfig, Component $component, $configId = null)
    {
    }

    public function loadInputData()
    {
    }

    public function storeOutput()
    {
    }

    public function storeDataArchive(array $tags)
    {
    }

    public function setFeatures($features)
    {
    }
}
