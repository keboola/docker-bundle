<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\Usage\Adapter;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Symfony\Component\Filesystem\Filesystem;
use Keboola\Syrup\Exception\ApplicationException;

class UsageFile
{
    /**
     * @var string
     */
    private $dataDir;

    /**
     * @var string
     */
    private $format;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var JobMapper
     */
    private $jobMapper;

    /**
     * @var string
     */
    private $jobId;

    public function __construct($dataDir, $format, JobMapper $jobMapper, $jobId)
    {
        $this->dataDir = $dataDir;
        $this->format = $format;
        $this->jobMapper = $jobMapper;
        $this->jobId = $jobId;

        $this->fs = new Filesystem;
        $this->adapter = new Adapter($format);
    }

    /**
     * Stores usage to ES job
     */
    public function storeUsage()
    {
        $usageFileName = $this->dataDir . '/out/usage' . $this->adapter->getFileExtension();
        if ($this->fs->exists($usageFileName)) {
            $usage = $this->adapter->readFromFile($usageFileName);
            $job = $this->jobMapper->get($this->jobId);
            if ($job !== null) {
                $job = $job->setUsage($usage);
                $this->jobMapper->update($job);
            } else {
                throw new ApplicationException('Job not found', null, ['jobId' => $this->jobId]);
            }
        }
    }
}
