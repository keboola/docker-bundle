<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration;
use Keboola\StagingProvider\Staging\File\LocalStaging;
use Keboola\StagingProvider\Staging\StagingClass;
use Keboola\StagingProvider\Staging\StagingProvider;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Staging\Workspace\LazyWorkspaceStaging;
use Keboola\StagingProvider\Workspace\Configuration\NetworkPolicy;
use Keboola\StagingProvider\Workspace\Configuration\WorkspaceCredentials;
use Keboola\StagingProvider\Workspace\Credentials\CredentialsProvider;
use Keboola\StagingProvider\Workspace\ProviderConfig\ExistingWorkspaceConfig;
use Keboola\StagingProvider\Workspace\ProviderConfig\NewWorkspaceConfig;
use Keboola\StagingProvider\Workspace\ProviderConfig\WorkspaceConfigInterface;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApiBranch\StorageApiToken;
use Psr\Log\LoggerInterface;

class StagingWorkspaceFacadeFactory
{
    public function __construct(
        private readonly WorkspaceProvider $workspaceProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createStagingWorkspaceFacade(
        StorageApiToken $storageApiToken,
        ComponentSpecification $component,
        Configuration $configuration,
        ?string $configId,
    ): StagingWorkspaceFacade {
        $inputStagingType = StagingType::from($component->getInputStagingStorage());
        $outputStagingType = StagingType::from($component->getOutputStagingStorage());

        // input and output staging type are the same, thanks to validateComponentStaging
        $this->validateComponentStaging($inputStagingType, $outputStagingType);
        $stagingType = $inputStagingType;

        if ($stagingType->getStagingClass() !== StagingClass::Workspace) {
            $workspace = null;
        } else {
            $workspaceProviderConfig = $this->getWorkspaceProviderConfig(
                $storageApiToken,
                $stagingType,
                $component,
                $configuration,
                $configId,
            );

            $workspace = new LazyWorkspaceStaging(
                $this->workspaceProvider,
                $workspaceProviderConfig,
            );
        }

        $stagingProvider = new StagingProvider(
            stagingType: $stagingType,
            workspaceStaging: $workspace,
            localStaging: null, // local staging is not used in this context
        );

        return new StagingWorkspaceFacade(
            $this->workspaceProvider,
            $stagingProvider,
            $this->logger,
        );
    }

    private function validateComponentStaging(
        StagingType $stagingStorageInput,
        StagingType $stagingStorageOutput,
    ): void {
        if ($stagingStorageInput->getStagingClass() === StagingClass::Workspace &&
            $stagingStorageOutput->getStagingClass() === StagingClass::Workspace &&
            $stagingStorageInput !== $stagingStorageOutput
        ) {
            throw new ApplicationException(sprintf(
                'Component staging setting mismatch - input: "%s", output: "%s".',
                $stagingStorageInput->value,
                $stagingStorageOutput->value,
            ));
        }
    }

    private function getWorkspaceProviderConfig(
        StorageApiToken $storageApiToken,
        StagingType $stagingType,
        ComponentSpecification $component,
        Configuration $configuration,
        ?string $configId,
    ): WorkspaceConfigInterface {
        $backendConfig = $configuration->runtime?->backend;
        $useReadonlyRole = $configuration->storage->input->readOnlyStorageAccess;

        $externallyManagedWorkspaceCredentials = $backendConfig?->workspaceCredentials;
        if ($externallyManagedWorkspaceCredentials) {
            // Externally managed workspaces are persistent
            $workspaceConfig = new ExistingWorkspaceConfig(
                workspaceId: $externallyManagedWorkspaceCredentials->id,
                credentials: new CredentialsProvider(new WorkspaceCredentials(
                    $externallyManagedWorkspaceCredentials->getCredentials(),
                )),
            );
            $this->logger->notice(sprintf(
                'Using provided workspace "%s".',
                $externallyManagedWorkspaceCredentials->id,
            ));
            return $workspaceConfig;
        }

        $workspaceLoginType = null;
        if ($stagingType === StagingType::WorkspaceSnowflake && $component->useSnowflakeKeyPairAuth()) {
            $workspaceLoginType = WorkspaceLoginType::SNOWFLAKE_SERVICE_KEYPAIR;
        }

        $this->logger->notice(sprintf(
            'Creating a new %s workspace.',
            $useReadonlyRole ? 'readonly ephemeral' : 'ephemeral',
        ));

        return new NewWorkspaceConfig(
            storageApiToken: $storageApiToken,
            stagingType: $stagingType,
            componentId: $component->getId(),
            configId: $configId,
            size: $backendConfig?->type,
            useReadonlyRole: $useReadonlyRole,
            networkPolicy: NetworkPolicy::SYSTEM,
            loginType: $workspaceLoginType,
        );
    }
}
