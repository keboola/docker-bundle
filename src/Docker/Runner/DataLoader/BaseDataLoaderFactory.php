<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\StagingProvider\Staging\File\LocalStaging;
use Keboola\StagingProvider\Staging\StagingProvider;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Staging\Workspace\LazyWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\WorkspaceStagingInterface;
use Keboola\StagingProvider\Workspace\ProviderConfig\ExistingWorkspaceConfig;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use LogicException;
use Psr\Log\LoggerInterface;

abstract class BaseDataLoaderFactory
{
    public function __construct(
        protected readonly WorkspaceProvider $workspaceProvider,
        protected readonly LoggerInterface $logger,
        protected readonly string $dataDirectory,
    ) {
    }

    protected function createStagingProvider(
        StagingType $stagingType,
        ?string $stagingWorkspaceId,
    ): StagingProvider {
        if ($stagingWorkspaceId === null) {
            $stagingWorkspace = null;
        } else {
            $stagingWorkspace = new LazyWorkspaceStaging(
                $this->workspaceProvider,
                new ExistingWorkspaceConfig($stagingWorkspaceId),
            );
        }

        $stagingProvider = new StagingProvider(
            $stagingType,
            $stagingWorkspace,
            new LocalStaging($this->dataDirectory),
        );

        if ($stagingProvider->getTableDataStaging() instanceof WorkspaceStagingInterface xor
            $stagingWorkspace !== null
        ) {
            throw new LogicException('Staging workspace ID must be configured for component with workspace staging.');
        }

        return $stagingProvider;
    }
}
