<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Symfony\Component\Filesystem\Filesystem;

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
     * @var UsageFileAdapter
     */
    private $adapter;

    public function __construct($dataDir, $format)
    {
        $this->dataDir = $dataDir;
        $this->format = $format;

        $this->fs = new Filesystem;
        $this->adapter = new UsageFileAdapter($format);
    }

    public function storeUsage()
    {
        $usageFileName = $this->dataDir . '/out/usage' . $this->adapter->getFileExtension();
        if ($this->fs->exists($usageFileName)) {
            $usage = $this->adapter->readFromFile($usageFileName);
            // TODO: save usage
        }
    }
}
