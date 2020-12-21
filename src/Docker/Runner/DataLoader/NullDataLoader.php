<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

class NullDataLoader implements DataLoaderInterface
{
    public function __construct(ClientWrapper $clientWrapper, LoggerInterface $logger, $dataDirectory, array $storageConfig, Component $component, OutputFilterInterface $outputFilter, $configId = null, $configRowId = null)
    {
    }

    public function loadInputData(InputTableStateList $inputTableStateList)
    {
        return new InputTableStateList([]);
    }

    public function storeOutput()
    {
    }

    public function storeDataArchive($fileName, array $tags)
    {
    }

    public function getWorkspaceCredentials()
    {
        return [];
    }

    public function cleanWorkspace()
    {
    }
}
