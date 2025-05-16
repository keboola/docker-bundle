<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\StagingProvider\Provider\Configuration\NetworkPolicy;
use Keboola\StagingProvider\Provider\Configuration\WorkspaceBackendConfig;
use Keboola\StagingProvider\Provider\Credentials\ExistingCredentialsProvider;
use Keboola\StagingProvider\Provider\ExistingWorkspaceProvider;
use Keboola\StagingProvider\Provider\InvalidWorkspaceProvider;
use Keboola\StagingProvider\Provider\NewWorkspaceProvider;
use Keboola\StagingProvider\Provider\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Provider\WorkspaceProviderInterface;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Psr\Log\LoggerInterface;

class WorkspaceProviderFactory
{
    public function __construct(
        private readonly Components $componentsApiClient,
        private readonly Workspaces $workspacesApiClient,
        private readonly SnowflakeKeypairGenerator $snowflakeKeypairGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getWorkspaceStaging(
        string $stagingStorage,
        ComponentSpecification $component,
        ?string $configId,
        array $backendConfig,
        ?bool $useReadonlyRole,
        ?ExternallyManagedWorkspaceCredentials $externallyManagedWorkspaceCredentials,
    ): WorkspaceProviderInterface {
        if (!in_array($stagingStorage, AbstractStrategyFactory::WORKSPACE_TYPES, true)) {
            return new InvalidWorkspaceProvider($stagingStorage);
        }

        if ($externallyManagedWorkspaceCredentials) {
            // Externally managed workspaces are persistent
            $workspaceStaging = new ExistingWorkspaceProvider(
                $this->workspacesApiClient,
                $externallyManagedWorkspaceCredentials->id,
                new ExistingCredentialsProvider(
                    $externallyManagedWorkspaceCredentials->getWorkspaceCredentials(),
                ),
            );
            $this->logger->notice(sprintf(
                'Using provided workspace "%s".',
                $externallyManagedWorkspaceCredentials->id,
            ));
            return $workspaceStaging;
        }

        $workspaceLoginType = null;
        if ($stagingStorage === AbstractStrategyFactory::WORKSPACE_SNOWFLAKE && $component->useSnowflakeKeyPairAuth()) {
            $workspaceLoginType = WorkspaceLoginType::SNOWFLAKE_SERVICE_KEYPAIR;
        }

        $workspaceBackendConfig = new WorkspaceBackendConfig(
            $stagingStorage,
            $backendConfig['type'] ?? null,
            $useReadonlyRole,
            NetworkPolicy::SYSTEM,
            $workspaceLoginType,
        );

        $this->logger->notice(sprintf(
            'Creating a new %s workspace.',
            $useReadonlyRole ? 'readonly ephemeral' : 'ephemeral',
        ));

        return new NewWorkspaceProvider(
            $this->workspacesApiClient,
            $this->componentsApiClient,
            $this->snowflakeKeypairGenerator,
            $workspaceBackendConfig,
            $component->getId(),
            $configId,
        );
    }
}
