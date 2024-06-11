<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use InvalidArgumentException;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\Gelf\ServerFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class Component
{
    private string $id;
    private array $data;
    private string $networkType;
    private array $features;
    private array $dataTypesConfiguration;
    private array $processorConfiguration;

    /**
     * Component constructor.
     * @param array $componentData Component data as returned by Storage API
     */
    public function __construct(array $componentData)
    {
        $this->id = empty($componentData['id']) ? '' : $componentData['id'];
        $componentData['data'] = empty($componentData['data']) ? [] : $componentData['data'];

        try {
            $validateComponentData = (new Configuration\Component())->parse(['config' => $componentData]);
        } catch (InvalidConfigurationException $e) {
            throw new ApplicationException(
                'Component definition is invalid. Verify the deployment setup and the repository settings ' .
                'in the Developer Portal. Detail: ' . $e->getMessage(),
                $e,
                $componentData['data'],
            );
        }
        $this->data = $validateComponentData['data'];

        $this->dataTypesConfiguration = $validateComponentData['dataTypesConfiguration'] ?? [];
        $this->processorConfiguration = $validateComponentData['processorConfiguration'] ?? [];

        $this->features = $validateComponentData['features'] ?? [];

        $this->networkType = $this->data['network'];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSanitizedComponentId(): string
    {
        return preg_replace('/[^a-zA-Z0-9-]/i', '-', $this->getId());
    }

    public function getConfigurationFormat(): string
    {
        return $this->data['configuration_format'];
    }

    public function getImageParameters(): array
    {
        return $this->data['image_parameters'];
    }

    public function hasDefaultBucket(): bool
    {
        return !empty($this->data['default_bucket']);
    }

    public function getDefaultBucketName(string $configId): string
    {
        return $this->data['default_bucket_stage'] . '.c-' . $this->getSanitizedComponentId() . '-' . $configId;
    }

    public function forwardToken(): bool
    {
        return (bool) $this->data['forward_token'];
    }

    public function forwardTokenDetails(): bool
    {
        return (bool) $this->data['forward_token_details'];
    }

    public function getType(): string
    {
        return $this->data['definition']['type'];
    }

    public function runAsRoot(): bool
    {
        return in_array('container-root-user', $this->features);
    }

    public function overrideKeepalive60s(): bool
    {
        return in_array('container-tcpkeepalive-60s-override', $this->features);
    }

    public function blockBranchJobs(): bool
    {
        return in_array('dev-branch-job-blocked', $this->features);
    }

    public function branchConfigurationsAreUnsafe(): bool
    {
        return in_array('dev-branch-configuration-unsafe', $this->features);
    }

    public function allowBranchMapping(): bool
    {
        return in_array('dev-mapping-allowed', $this->features);
    }

    public function hasNoSwap(): bool
    {
        return in_array('no-swap', $this->features);
    }

    public function allowUseFileStorageOnly(): bool
    {
        return in_array('allow-use-file-storage-only', $this->features);
    }

    public function allowMlflowArtifactsAccess(): bool
    {
        return in_array('mlflow-artifacts-access', $this->features, true);
    }

    public function getLoggerType(): string
    {
        if (!empty($this->data['logging'])) {
            return $this->data['logging']['type'];
        }
        return 'standard';
    }

    public function getLoggerVerbosity(): array
    {
        if (!empty($this->data['logging'])) {
            return $this->data['logging']['verbosity'];
        }
        return [];
    }

    public function getLoggerServerType(): string
    {
        if (!empty($this->data['logging'])) {
            switch ($this->data['logging']['gelf_server_type']) {
                case 'udp':
                    return ServerFactory::SERVER_UDP;
                case 'tcp':
                    return ServerFactory::SERVER_TCP;
                case 'http':
                    return ServerFactory::SERVER_HTTP;
                default:
                    throw new ApplicationException(
                        "Server type '{$this->data['logging']['gelf_server_type']}' not supported",
                    );
            }
        }
        return ServerFactory::SERVER_TCP;
    }

    public function getNetworkType(): string
    {
        return $this->networkType;
    }

    public function getMemory(): string
    {
        return $this->data['memory'];
    }

    public function getProcessTimeout(): int
    {
        return (int) ($this->data['process_timeout']);
    }

    public function getImageDefinition(): array
    {
        return $this->data['definition'];
    }

    public function setImageTag(string $tag): void
    {
        $this->data['definition']['tag'] = $tag;
    }

    public function getImageTag(): string
    {
        return $this->data['definition']['tag'];
    }

    public function getStagingStorage(): array
    {
        return $this->data['staging_storage'];
    }

    public function isApplicationErrorDisabled(): bool
    {
        return (bool) $this->data['logging']['no_application_errors'];
    }

    public function getDataTypesSupport(): string
    {
        return $this->dataTypesConfiguration['dataTypesSupport'] ?? 'none';
    }

    public function getAllowedProcessorPosition(): string
    {
        return $this->processorConfiguration['allowedProcessorPosition'] ?? 'any';
    }
}
