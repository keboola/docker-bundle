<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class JobDefinition
{
    private array $configuration;

    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function __construct(
        array $configuration,
        private readonly ComponentSpecification $component,
        private readonly ?string $configId = null,
        private readonly ?string $configVersion = null,
        private readonly array $state = [],
        private readonly ?string $rowId = null,
        private readonly bool $isDisabled = false,
        /** @var ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT */
        private readonly string $branchType = ObjectEncryptor::BRANCH_TYPE_DEFAULT,
        private readonly ?array $inputVariableValues = null,
    ) {
        $this->configuration = $this->normalizeConfiguration($configuration);
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

    public function getComponent(): ComponentSpecification
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

    /**
     * @return ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT
     */
    public function getBranchType(): string
    {
        return $this->branchType;
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

    public function getInputVariableValues(): ?array
    {
        return $this->inputVariableValues;
    }
}
