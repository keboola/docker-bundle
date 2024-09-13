<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Exception\ExternalWorkspaceException;
use Keboola\StagingProvider\Provider\Credentials\DatabaseWorkspaceCredentials;

readonly class ExternallyManagedWorkspaceCredentials
{
    private function __construct(
        public string $id,
        public string $type,
        public string $password,
    ) {
    }

    public static function fromArray(array $credentials): self
    {
        if (!isset($credentials['id']) || !isset($credentials['type']) || !isset($credentials['#password'])) {
            throw new ExternalWorkspaceException(
                'Missing required fields (id, type, #password) in workspace_credentials',
            );
        }
        if ($credentials['type'] !== 'snowflake') {
            throw new ExternalWorkspaceException(sprintf('Unsupported workspace type "%s"', $credentials['type']));
        }
        return new self(
            (string) $credentials['id'],
            $credentials['type'],
            (string) $credentials['#password'],
        );
    }

    public function getDatabaseCredentials(): DatabaseWorkspaceCredentials
    {
        // array in format of https://keboola.docs.apiary.io/#reference/workspaces/password-reset/password-reset
        return DatabaseWorkspaceCredentials::fromPasswordResetArray(['password' => $this->password]);
    }
}
