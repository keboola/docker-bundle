<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\GitHubApp;

readonly class MintedGitHubAppToken
{
    public function __construct(
        public string $encryptedToken,
        public string $expiresAt,
    ) {
    }
}
