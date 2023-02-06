<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class JobDefinition
{
    private ?string $configId;
    private ?string $rowId;
    private ?string $configVersion;
    private Component $component;
    private array $configuration;
    private array $state;
    private bool $isDisabled;

    public function __construct(
        array $configuration,
        Component $component,
        ?string $configId = null,
        ?string $configVersion = null,
        array $state = [],
        ?string $rowId = null,
        bool $isDisabled = false
    ) {
        $this->configuration = $this->normalizeConfiguration($configuration);
        $this->component = $component;
        $this->configId = $configId;
        $this->configVersion = $configVersion;
        $this->rowId = $rowId;
        $this->isDisabled = $isDisabled;
        $this->state = $state;
    }

    public function getComponentId(): string
    {
        return $this->component->getId();
    }

    public function getConfigId(): ?string
    {
        return $this->configId;
    }

    public function getRowId(): ?string
    {
        return $this->rowId;
    }

    public function getConfigVersion(): ?string
    {
        return $this->configVersion;
    }

    public function getComponent(): Component
    {
        return $this->component;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function isDisabled(): bool
    {
        return $this->isDisabled;
    }

    private function normalizeConfiguration(array $configuration): array
    {
        try {
            $configuration = (new Configuration\Container())->parse(['container' => $configuration]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        $configuration['storage'] = empty($configuration['storage']) ? [] : $configuration['storage'];
        $configuration['processors'] = empty($configuration['processors']) ? [] : $configuration['processors'];
        $configuration['parameters'] = empty($configuration['parameters']) ? [] : $configuration['parameters'];

        return $configuration;
    }
}
