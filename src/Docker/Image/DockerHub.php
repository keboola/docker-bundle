<?php

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Symfony\Component\Process\Process;

class DockerHub extends Image
{
    protected function pullImage()
    {
        $proxy = $this->getRetryProxy();
        $process = new Process("sudo docker pull " . escapeshellarg($this->getFullImageId()));
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
            $this->logImageHash();
        } catch (\Exception $e) {
            throw new ApplicationException("Cannot pull image '{$this->getFullImageId()}': ({$process->getExitCode()}) {$process->getErrorOutput()}", $e);
        }
    }
}
