<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;

interface DataLoaderInterface
{
    public function loadInputData(): StorageState;

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue;

    public function storeDataArchive(string $fileName, array $tags): void;

    public function getWorkspaceCredentials(): array;

    public function cleanWorkspace(): void;
}
