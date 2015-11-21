<?php

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Symfony\Component\Process\Process;

class QuayIO extends Image
{

    /**
     * @return string
     */
    public function getFullImageId()
    {
        return "quay.io/" . $this->getImageId() . ":" . $this->getTag();
    }

    /**
     * @inheritdoc
     */
    public function prepare(Container $container, array $configData, $containerId)
    {
        try {
            $process = new Process("sudo docker pull " . escapeshellarg($this->getFullImageId()));
            $process->setTimeout(3600);
            $process->run();
        } catch (\Exception $e) {
            throw new ApplicationException("Failed to prepare container {$this->getFullImageId()}, error: ".$e->getMessage(), $e);
        }

        if (!$process->isSuccessful()) {
            throw new ApplicationException(
                "Cannot pull image '{$this->getFullImageId()}': ({$process->getExitCode()}) {$process->getErrorOutput()}"
            );
        }
    }
}
