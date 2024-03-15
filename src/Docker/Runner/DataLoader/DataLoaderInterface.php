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
        string $dataDirectory,
        JobDefinition $jobDefinition,
        OutputFilterInterface $outputFilter,
    );

    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
    ): StorageState;

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue;

    public function storeDataArchive(string $fileName, array $tags): void;

    public function getWorkspaceCredentials(): array;

    public function cleanWorkspace(): void;

    public function getWorkspaceBackendSize(): ?string;
}
