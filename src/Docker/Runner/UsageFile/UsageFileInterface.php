<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\UsageFile;

interface UsageFileInterface
{
    public function __construct();

    public function setDataDir(string $dataDir): void;

    public function storeUsage(): void;
}
