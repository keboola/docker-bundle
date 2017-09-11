<?php

namespace Keboola\DockerBundle\Docker\Image;

use Aws\Credentials\Credentials;
use Aws\Ecr\EcrClient;
use Aws\Ecr\Exception\EcrException;
use Aws\Exception\CredentialsException;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Psr\Log\LoggerInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistry extends Image
{
    protected $awsRegion = 'us-east-1';

    public function __construct(ObjectEncryptor $encryptor, Component $component, LoggerInterface $logger)
    {
        parent::__construct($encryptor, $component, $logger);
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
            'version' => '2015-09-21'
        ));
        try {
            $authorization = $ecrClient->getAuthorizationToken(["registryIds" => [$this->getAwsAccountId()]]);
        } catch (CredentialsException $e) {
            throw new LoginFailedException($e->getMessage());
        } catch (EcrException $e) {
            throw new LoginFailedException($e->getMessage());
        }
        // decode token and extract user
        list($user, $token) = explode(":", base64_decode($authorization->get('authorizationData')[0]['authorizationToken']));

        $loginParams[] = "--username=" . escapeshellarg($user);
        $loginParams[] = "--password=" . escapeshellarg($token);
        $loginParams[] = escapeshellarg($authorization->get('authorizationData')[0]['proxyEndpoint']);
        return join(" ", $loginParams);
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
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new ExponentialBackOffPolicy(10000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);

        $command = "sudo docker run --rm -v /var/run/docker.sock:/var/run/docker.sock " .
            "docker:1.11 sh -c " .
            escapeshellarg(
                "docker login " . $this->getLoginParams() .  " " .
                "&& docker pull " . escapeshellarg($this->getFullImageId()) . " " .
                "&& docker logout " . $this->getLogoutParams()
            );
        $process = new Process($command);
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
            throw new ApplicationException("Cannot pull image '{$this->getFullImageId()}': ({$process->getExitCode()}) {$process->getErrorOutput()} {$process->getOutput()}", $e);
        }
    }
}
