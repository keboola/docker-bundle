<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Image;

use LogicException;
use SensitiveParameter;

class ReplicatedRegistry
{
    public function __construct(
        private readonly bool $useReplicatedRegistry,
        private readonly string $replicatedRegistryUrl,
        private readonly string $loginUsername,
        #[SensitiveParameter]
        private readonly string $loginPassword,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->useReplicatedRegistry && $this->replicatedRegistryUrl !== '';
    }

    public function composeImageUrl(string $definitionName): string
    {
        if (!$this->isEnabled()) {
            throw new LogicException('Replicated registry is not enabled');
        }
        return rtrim($this->replicatedRegistryUrl, '/') . '/' . trim($definitionName, '/');
    }

    public function getLoginParams(): string
    {
        $registryHost = $this->getRegistryHost();

        $loginParams = [];
        $loginParams[] = '--username=' . escapeshellarg($this->loginUsername);
        $loginParams[] = '--password=' . escapeshellarg($this->loginPassword);
        $loginParams[] = escapeshellarg($registryHost);
        return implode(' ', $loginParams);
    }

    public function getLogoutParams(): string
    {
        return escapeshellarg($this->getRegistryHost());
    }

    private function getRegistryHost(): string
    {
        $parts = explode('/', $this->replicatedRegistryUrl);
        return $parts[0];
    }
}
