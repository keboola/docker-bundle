<?php

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Process\Process;

class DockerHub extends Image
{
    /**
     * @inheritdoc
     */
    public function prepare(Container $container, array $configData, $containerId)
    {
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new ExponentialBackOffPolicy(10000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
        $process = new Process("sudo docker pull " . escapeshellarg($this->getFullImageId()));
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
        } catch (\Exception $e) {
            throw new ApplicationException("Cannot pull image '{$this->getFullImageId()}': ({$process->getExitCode()}) {$process->getErrorOutput()}", $e);
        }
    }
}
