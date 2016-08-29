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
        try {
            $proxy->call(function () {
                $process = new Process("sudo docker pull x" . escapeshellarg($this->getFullImageId()));
                $process->setTimeout(3600);
                $process->mustRun();
            });
        } catch (\Exception $e) {
            throw new ApplicationException("Failed to prepare container {$this->getFullImageId()}, error: ".$e->getMessage(), $e);
        }
    }
}
