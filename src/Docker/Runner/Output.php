<?php

namespace Keboola\DockerBundle\Docker\Runner;

class Output
{
    /**
     * @var array
     */
    private $images = [];

    /**
     * @var string
     */
    private $output;

    /**
     * @var string
     */
    private $configVersion;

    /**
     * @var array
     */
    private $storageJobIds;

    /**
     * Output constructor.
     *
     * @param array $images
     * @param string $output
     * @param $configVersion
     */
    public function __construct(array $images, $output, $configVersion)
    {
        $this->images = $images;
        $this->output = $output;
        $this->configVersion = $configVersion;
    }

    /**
     * @return array
     */
    public function getImages()
    {
        return $this->images;
    }

    /**
     * @return string
     */
    public function getProcessOutput()
    {
        return $this->output;
    }

    /**
     * @return string
     */
    public function getConfigVersion()
    {
        return $this->configVersion;
    }

    public function setStorageJobIds(array $jobIds)
    {
        $this->storageJobIds = $jobIds;
    }

    public function getStorageJobIds()
    {
        return $this->storageJobIds;
    }
}
