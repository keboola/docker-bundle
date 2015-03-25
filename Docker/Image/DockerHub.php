<?php

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Symfony\Component\Process\Process;

class DockerHub extends Image
{
    /**
     * @var string
     */
    protected $dockerHubImageId;

    /**
     * @return string
     */
    public function getDockerHubImageId()
    {
        return $this->dockerHubImageId;
    }

    /**
     * @param string $dockerHubImageId
     * @return $this
     */
    public function setDockerHubImageId($dockerHubImageId)
    {
        $this->dockerHubImageId = $dockerHubImageId;
        return $this;
    }

    /**
     * @param Container $container
     * @return string
     * @throws \Exception
     */
    public function prepare(Container $container)
    {
        $tag = $this->getDockerHubImageId() . ":" . $container->getVersion();
        $process = new Process("plink -load docker sudo docker pull " . escapeshellarg($tag));
        $process->setTimeout(3600);
        $process->run();
        $output = $process->getOutput();
        $errOutput = $process->getErrorOutput();
        if (!$process->isSuccessful()) {
            throw new \Exception("Cannot pull image '$tag': ({$process->getExitCode()}) {$process->getErrorOutput()}");
        }
        return $tag;
    }
}
