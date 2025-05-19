<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\State\State;
use Keboola\JobQueue\JobConfiguration\Mapping\InputDataLoader;
use Keboola\StagingProvider\Staging\File\FileFormat;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StorageApiBranch\ClientWrapper;

class InputDataLoaderFactory extends BaseDataLoaderFactory
{
    public function createInputDataLoader(
        ClientWrapper $clientWrapper,
        ComponentSpecification $component,
        Configuration $jobConfiguration,
        State $jobState,
        ?string $stagingWorkspaceId,
    ): InputDataLoader {
        $stagingProvider = $this->createStagingProvider(
            StagingType::from($component->getInputStagingStorage()),
            $stagingWorkspaceId,
        );

        $strategyFactory = new InputStrategyFactory(
            $stagingProvider,
            $clientWrapper,
            $this->logger,
            FileFormat::from($component->getConfigurationFormat()),
        );

        return new InputDataLoader(
            $strategyFactory,
            $clientWrapper,
            $component,
            $jobConfiguration,
            $jobState,
            $this->logger,
            'in/',
        );
    }
}
