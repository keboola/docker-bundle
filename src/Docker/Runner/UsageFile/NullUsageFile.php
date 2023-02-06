<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\UsageFile;

class NullUsageFile implements UsageFileInterface
{
    public function __construct()
    {
    }

    public function setDataDir(string $dataDir): void
    {
    }

    public function storeUsage(): void
    {
    }
}
