<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Workspace\Configuration\NetworkPolicy;
use Keboola\StagingProvider\Workspace\Credentials\CredentialsProvider;
use Keboola\StagingProvider\Workspace\Credentials\ResetCredentialsProvider;
use Keboola\StagingProvider\Workspace\ProviderConfig\ExistingWorkspaceConfig;
use Keboola\StagingProvider\Workspace\ProviderConfig\NewWorkspaceConfig;
use Keboola\StagingProvider\Workspace\ProviderConfig\WorkspaceConfigInterface;
use Keboola\StagingProvider\Workspace\SnowflakeKeypairGenerator;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\StorageApiToken;
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

    public function getWorkspaceProviderConfig(
        StorageApiToken $storageApiToken,
        StagingType $stagingType,
        Component $component,
        ?string $configId,
        array $backendConfig,
        ?bool $useReadonlyRole,
        ?ExternallyManagedWorkspaceCredentials $externallyManagedWorkspaceCredentials,
    ): WorkspaceConfigInterface {
        if ($externallyManagedWorkspaceCredentials) {
            // Externally managed workspaces are persistent
            $workspaceConfig = new ExistingWorkspaceConfig(
                workspaceId: $externallyManagedWorkspaceCredentials->id,
                credentials: new CredentialsProvider(
                    $externallyManagedWorkspaceCredentials->getWorkspaceCredentials(),
                ),
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
            size: $backendConfig['type'] ?? null,
            useReadonlyRole: $useReadonlyRole,
            networkPolicy: NetworkPolicy::SYSTEM,
            loginType: $workspaceLoginType,
            isReusable: false,
        );
    }
}
