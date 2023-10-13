<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

interface DataLoaderInterface
{
    public function __construct(
        ClientWrapper $clientWrapper,
        LoggerInterface $logger,
        $dataDirectory,
        JobDefinition $jobDefinition,
        OutputFilterInterface $outputFilter,
    );

    /**
     * @return StorageState
     */
    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
    );

    /**
     * @return LoadTableQueue|null
     */
    public function storeOutput($isFailedJob = false);

    public function storeDataArchive($fileName, array $tags);

    /**
     * @return array
     */
    public function getWorkspaceCredentials();

    public function cleanWorkspace();

    public function getWorkspaceBackendSize(): ?string;
}
