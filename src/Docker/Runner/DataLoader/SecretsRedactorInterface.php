<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

interface SecretsRedactorInterface
{
    public function redactSecrets(string $text): string;
}
