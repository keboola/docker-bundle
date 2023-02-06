<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\OutputFilter;

use Keboola\DockerBundle\Docker\Container\WtfWarningFilter;

class MinimalFilter implements OutputFilterInterface
{
    public function addValue(string $value): void
    {
    }

    public function collectValues(array $data): void
    {
    }

    public function filter(string $text): string
    {
        return WtfWarningFilter::filter($text);
    }
}
