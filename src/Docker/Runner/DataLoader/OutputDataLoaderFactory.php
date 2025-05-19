<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration;
use Keboola\JobQueue\JobConfiguration\Mapping\InputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\OutputDataLoader;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\StagingProvider\Staging\File\FileFormat;
use Keboola\StagingProvider\Staging\File\LocalStaging;
use Keboola\StagingProvider\Staging\StagingProvider;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Staging\Workspace\LazyWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\WorkspaceStagingInterface;
use Keboola\StagingProvider\Workspace\ProviderConfig\ExistingWorkspaceConfig;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StorageApiBranch\ClientWrapper;
use LogicException;
use Psr\Log\LoggerInterface;

class OutputDataLoaderFactory extends BaseDataLoaderFactory
{
    public function createOutputDataLoader(
        ClientWrapper $clientWrapper,
        ComponentSpecification $component,
        Configuration $configuration,
        ?string $configId,
        ?string $configRowId,
        ?string $stagingWorkspaceId,
    ): OutputDataLoader {
        $stagingProvider = $this->createStagingProvider(
            StagingType::from($component->getOutputStagingStorage()),
            $stagingWorkspaceId,
        );

        $strategyFactory = new OutputStrategyFactory(
            $stagingProvider,
            $clientWrapper,
            $this->logger,
            FileFormat::from($component->getConfigurationFormat()),
        );

        return new OutputDataLoader(
            $strategyFactory,
            $clientWrapper,
            $component,
            $configuration,
            $configId,
            $configRowId,
            $this->logger,
            'out/',
        );
    }
}
