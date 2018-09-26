<?php

namespace Keboola\DockerBundle\Docker\Runner\UsageFile;

use Keboola\DockerBundle\Docker\Configuration\Usage\Adapter;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Symfony\Component\Filesystem\Filesystem;

class UsageFile implements UsageFileInterface
{
    /**
     * @var string
     */
    private $dataDir = null;

    /**
     * @var string
     */
    private $format = null;

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
    private $jobMapper = null;

    /**
     * @var string
     */
    private $jobId = null;

    public function __construct()
    {
        $this->fs = new Filesystem;
    }

    /**
     * Stores usage to ES job
     */
    public function storeUsage()
    {
        if ($this->dataDir === null || $this->format === null || $this->jobId === null || $this->jobMapper === null) {
            throw new ApplicationException('Usage file not initialized.');
        }
        $usageFileName = $this->dataDir . '/out/usage' . $this->adapter->getFileExtension();
        if ($this->fs->exists($usageFileName)) {
            $usage = $this->adapter->readFromFile($usageFileName);
            $job = $this->jobMapper->get($this->jobId);
            if ($job !== null) {
                $currentUsage = $job->getUsage();
                foreach ($usage as $usageItem) {
                    $currentUsage[] = $usageItem;
                }
                if ($currentUsage) {
                    $job = $job->setUsage($currentUsage);
                    $this->jobMapper->update($job);
                }
            } else {
                throw new ApplicationException('Job not found', null, ['jobId' => $this->jobId]);
            }
        }
    }

    public function setDataDir($dataDir)
    {
        $this->dataDir = $dataDir;
    }

    public function setFormat($format)
    {
        $this->format = $format;
        $this->adapter = new Adapter($format);
    }

    public function setJobId($jobId)
    {
        $this->jobId = $jobId;
    }

    public function setJobMapper($jobMapper)
    {
        $this->jobMapper = $jobMapper;
    }
}
