<?php

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Symfony\Component\Process\Process;

class DockerHub extends Image
{
    /**
     * @inheritdoc
     */
    public function prepare(Container $container, array $configData, array $volatileConfigData, $containerId)
    {
        $tag = $this->getImageId() . ":" . $container->getVersion();

        try {
            $process = new Process("sudo docker pull " . escapeshellarg($tag));
            $process->setTimeout(3600);
            $process->run();
        } catch (\Exception $e) {
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
