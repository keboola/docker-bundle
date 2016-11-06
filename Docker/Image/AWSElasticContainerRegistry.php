<?php

namespace Keboola\DockerBundle\Docker\Image;

use Aws\Credentials\Credentials;
use Aws\Ecr\EcrClient;
use Aws\Ecr\Exception\EcrException;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\Syrup\Exception\ApplicationException;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistry extends Image
{
    protected $awsAccessKeyId;
    protected $awsSecretKey;

    /**
     * @return mixed
     */
    public function getAwsAccessKeyId()
    {
        return $this->awsAccessKeyId;
    }

    /**
     * @param mixed $awsAccessKeyId
     * @return $this
     */
    public function setAwsAccessKeyId($awsAccessKeyId)
    {
        $this->awsAccessKeyId = $awsAccessKeyId;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getAwsSecretKey()
    {
        return $this->awsSecretKey;
    }

    /**
     * @param mixed $awsSecretKey
     * @return $this
     */
    public function setAwsSecretKey($awsSecretKey)
    {
        $this->awsSecretKey = $awsSecretKey;

        return $this;
    }

    /**
     * @return string
     */
    public function getLoginParams()
    {
        if (!$this->getAwsSecretKey() || !$this->getAwsAccessKeyId()) {
            throw new LoginFailedException("Missing AWS Access Key or Secret Key.");
        }

        $awsCredentials = new Credentials($this->getAwsAccessKeyId(), $this->getAwsSecretKey());
        $ecrClient = new EcrClient(array(
            'credentials' => $awsCredentials,
            'region' => 'us-east-1',
            'version' => '2015-09-21'
        ));
        try {
            $authorization = $ecrClient->getAuthorizationToken([]);
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
     * Run docker login and docker pull in DinD, login/logout race conditions
     */
    protected function pullImage()
    {
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new ExponentialBackOffPolicy(10000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);

        $command = "sudo docker run --rm -v /var/run/docker.sock:/var/run/docker.sock " .
            "docker:1.11-dind sh -c '" .
            "docker login " . $this->getLoginParams() .  " " .
            "&& docker pull " . escapeshellarg($this->getFullImageId()) . " " .
            "&& docker logout " . $this->getLogoutParams() .
            "'";
        $process = new Process($command);
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
        } catch (\Exception $e) {
            if (strpos($process->getOutput(), "403 Forbidden") !== false) {
                throw new LoginFailedException($process->getOutput());
            }
            throw new ApplicationException("Cannot pull image '{$this->getFullImageId()}': ({$process->getExitCode()}) {$process->getErrorOutput()} {$process->getOutput()}", $e);
        }
    }

    /**
     * @param array $config
     * @return $this
     */
    public function fromArray(array $config)
    {
        parent::fromArray($config);
        if (isset($config["definition"]["repository"])) {
            if (isset($config["definition"]["repository"]["aws_access_key_id"])) {
                $this->setAwsAccessKeyId($config["definition"]["repository"]["aws_access_key_id"]);
            }
            if (isset($config["definition"]["repository"]["#aws_secret_access_key"])) {
                $this->setAwsSecretKey(
                    $this->getEncryptor()->decrypt($config["definition"]["repository"]["#aws_secret_access_key"])
                );
            }
        }
        return $this;
    }
}
