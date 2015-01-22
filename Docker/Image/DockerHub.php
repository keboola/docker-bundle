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
        $process = new Process("sudo docker pull {$tag}");
        $process->setTimeout(360);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \Exception("Cannot pull image '$tag': ({$process->getExitCode()}) {$process->getErrorOutput()}");
        }
        return $tag;
    }

}