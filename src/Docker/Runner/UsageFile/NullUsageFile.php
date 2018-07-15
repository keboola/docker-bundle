<?php

namespace Keboola\DockerBundle\Docker\Runner\UsageFile;

class NullUsageFile implements UsageFileInterface
{
    public function __construct()
    {
    }

    public function setDataDir($dataDir)
    {
    }

    public function setFormat($format)
    {
    }

    public function setJobId($jobId)
    {
    }

    public function storeUsage()
    {
    }
}
