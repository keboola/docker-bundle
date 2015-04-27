<?php

namespace Keboola\DockerBundle\Docker\Image;

use Aws\CloudFront\Exception\Exception;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
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
     * @return string Image tag name.
     * @throws ApplicationException
     */
    public function prepare(Container $container)
    {
        $tag = $this->getDockerHubImageId() . ":" . $container->getVersion();

        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $process = new Process("docker pull " . escapeshellarg($tag));
            } else {
                $process = new Process("sudo docker pull " . escapeshellarg($tag));
            }
            $process->setTimeout(3600);
            $process->run();
        } catch (Exception $e) {
            throw new ApplicationException("Failed to prepare container {$tag}, error: ".$e->getMessage(), $e);
        }

        if (!$process->isSuccessful()) {
            throw new ApplicationException(
                "Cannot pull image '$tag': ({$process->getExitCode()}) {$process->getErrorOutput()}"
            );
        }

        return $tag;
    }
}
