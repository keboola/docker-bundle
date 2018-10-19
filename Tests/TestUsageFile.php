<?php

namespace Keboola\DockerBundle\Tests;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;

class TestUsageFile implements UsageFileInterface
{
    private $dataDir = '';
    private $usage = [];

    public function setDataDir($dataDir)
    {
        $this->dataDir = $dataDir;
    }

    public function storeUsage()
    {
        $usageFileName = $this->dataDir . '/out/usage.json';
        $this->usage = json_decode(file_get_contents($usageFileName), true);
    }

    public function getUsageData()
    {
        return $this->usage;
    }

    public function __construct()
    {
    }
}
