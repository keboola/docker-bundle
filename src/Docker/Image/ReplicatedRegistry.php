<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Image;

use SensitiveParameter;

class ReplicatedRegistry
{
    private const ECR_REGISTRY_URL = '147946154733.dkr.ecr.us-east-1.amazonaws.com';

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

    public function transformImageUrl(string $originalImageId): string
    {
        if (!empty($this->replicatedRegistryUrl)) {
            return str_replace(self::ECR_REGISTRY_URL, $this->replicatedRegistryUrl, $originalImageId);
        }
        return $originalImageId;
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
