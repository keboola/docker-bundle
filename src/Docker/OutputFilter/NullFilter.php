<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\OutputFilter;

class NullFilter implements OutputFilterInterface
{
    public function addValue(string $value): void
    {
    }

    public function collectValues(array $data): void
    {
    }

    public function redactSecrets(string $text): string
    {
        return $text;
    }
}
