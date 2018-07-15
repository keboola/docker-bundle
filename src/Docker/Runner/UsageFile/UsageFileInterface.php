<?php

namespace Keboola\DockerBundle\Docker\Runner\UsageFile;

interface UsageFileInterface
{
    public function __construct();

    public function setDataDir($dataDir);

    public function setFormat($format);

    public function setJobId($jobId);

    public function storeUsage();
}
