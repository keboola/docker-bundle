<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Symfony\Component\Process\Process;
use Throwable;

class QuayIO extends Image
{
    /**
     * @inheritdoc
     */
    public function getFullImageId()
    {
        return 'quay.io/' . $this->getImageId() . ':' . $this->getTag();
    }

    protected function pullImage()
    {
        $proxy = $this->getRetryProxy();
        $process = Process::fromShellCommandline('sudo docker pull ' . escapeshellarg($this->getFullImageId()));
        $process->setTimeout(3600);

        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
            $this->logImageHash();
        } catch (Throwable $e) {
            throw new ApplicationException(
                sprintf(
                    "Cannot pull image '%s': (%s) %s",
                    $this->getPrintableImageId(),
                    $process->getExitCode(),
                    $process->getErrorOutput(),
                ),
                $e,
            );
        }
    }
}
