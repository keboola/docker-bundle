<?php

namespace Keboola\DockerBundle\Docker\Image;

use Aws\Ecr\EcrClient;
use Aws\Result;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistry extends Image
{
    protected $awsRegion = 'us-east-1';
    private const CONNECT_TIMEOUT = 10;
    private const CONNECT_RETRIES = 2;
    private const TRANSFER_TIMEOUT = 120;

    public function __construct(Component $component, LoggerInterface $logger)
    {
        parent::__construct($component, $logger);
        if (!empty($component->getImageDefinition()["repository"]["region"])) {
            $this->awsRegion = $component->getImageDefinition()["repository"]["region"];
        }
    }

    /**
     * @return string
     */
    public function getAwsRegion()
    {
        return $this->awsRegion;
    }

    public function getAwsAccountId()
    {
        return substr($this->getImageId(), 0, strpos($this->getImageId(), '.'));
    }

    /**
     * @return string
     */
    public function getLoginParams()
    {
        $ecrClient = new EcrClient(array(
            'region' => $this->getAwsRegion(),
            'version' => '2015-09-21',
            'retries' => self::CONNECT_RETRIES,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TRANSFER_TIMEOUT,
        ));
        /** @var Result $authorization */
        $authorization = null;
        $proxy = $this->getRetryProxy();
        try {
            $proxy->call(function () use ($ecrClient, &$authorization) {
                try {
                    $authorization = $ecrClient->getAuthorizationToken(['registryIds' => [$this->getAwsAccountId()]]);
                    // \Exception because "Before PHP 7, Exception did not implement the Throwable interface."
                    // https://www.php.net/manual/en/class.exception.php
                } catch (\Exception $e) {
                    $this->logger->notice('Retrying AWS GetCredentials. error: ' . $e->getMessage());
                    throw $e;
                }
            });
        } catch (\Exception $e) {
            throw new LoginFailedException($e->getMessage(), $e);
        }
        // decode token and extract user
        list($user, $token) =
            explode(':', base64_decode($authorization->get('authorizationData')[0]['authorizationToken']));

        $loginParams[] = '--username=' . escapeshellarg($user);
        $loginParams[] = '--password=' . escapeshellarg($token);
        $loginParams[] = escapeshellarg($authorization->get('authorizationData')[0]['proxyEndpoint']);
        return join(' ', $loginParams);
    }

    /**
     * @return string
     */
    public function getLogoutParams()
    {
        $logoutParams = [];
        $logoutParams[] = escapeshellarg($this->getImageId());
        return join(" ", $logoutParams);
    }

    /**
     * Run docker login and docker pull in container, login/logout race conditions
     */
    protected function pullImage()
    {
        $proxy = $this->getRetryProxy();

        $command = "sudo docker run --rm -v /var/run/docker.sock:/var/run/docker.sock " .
            "docker:1.11 sh -c " .
            escapeshellarg(
                "docker login " . $this->getLoginParams() .  " " .
                "&& docker pull " . escapeshellarg($this->getFullImageId()) . " " .
                "&& docker logout " . $this->getLogoutParams()
            );
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
            $this->logImageHash();
        } catch (\Exception $e) {
            if (strpos($process->getOutput(), "403 Forbidden") !== false) {
                throw new LoginFailedException($process->getOutput());
            }
            throw new ApplicationException("Cannot pull image '{$this->getPrintableImageId()}': ({$process->getExitCode()}) {$process->getErrorOutput()} {$process->getOutput()}", $e);
        }
    }
}
