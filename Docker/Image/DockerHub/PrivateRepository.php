<?php

namespace Keboola\DockerBundle\Docker\Image\DockerHub;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\Syrup\Exception\ApplicationException;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Process\Process;

class PrivateRepository extends Image\DockerHub
{
    protected $loginUsername;
    protected $loginPassword;
    protected $loginServer;


    /**
     * @return mixed
     */
    public function getLoginUsername()
    {
        return $this->loginUsername;
    }

    /**
     * @param mixed $loginUsername
     * @return $this
     */
    public function setLoginUsername($loginUsername)
    {
        $this->loginUsername = $loginUsername;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getLoginPassword()
    {
        return $this->loginPassword;
    }

    /**
     * @param mixed $loginPassword
     * @return $this
     */
    public function setLoginPassword($loginPassword)
    {
        $this->loginPassword = $loginPassword;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getLoginServer()
    {
        return $this->loginServer;
    }

    /**
     * @param mixed $loginServer
     * @return $this
     */
    public function setLoginServer($loginServer)
    {
        $this->loginServer = $loginServer;

        return $this;
    }

    /**
     * @return string
     */
    public function getLoginParams()
    {
        // Login
        $loginParams = [];
        if ($this->getLoginUsername()) {
            $loginParams[] = "--username=" . escapeshellarg($this->getLoginUsername());
        }
        if ($this->getLoginPassword()) {
            $loginParams[] = "--password=" . escapeshellarg($this->getLoginPassword());
        }
        if ($this->getLoginServer()) {
            $loginParams[] = escapeshellarg($this->getLoginServer());
        }
        return join(" ", $loginParams);
    }

    /**
     * @return string
     */
    public function getLogoutParams()
    {
        $logoutParams = [];
        if ($this->getLoginServer()) {
            $logoutParams[] = escapeshellarg($this->getLoginServer());
        }
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
        $command = "sudo docker run --rm -v /var/lib/docker:/var/lib/docker -v /var/run/docker.sock:/var/run/docker.sock " .
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
            if (strpos($process->getOutput(), "Login with your Docker ID to push and pull images from Docker Hub.") !== false) {
                throw new LoginFailedException($process->getOutput());
            }
            if (strpos($process->getErrorOutput(), "unauthorized: incorrect username or password") !== false) {
                throw new LoginFailedException($process->getErrorOutput());
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
            if (isset($config["definition"]["repository"]["username"])) {
                $this->setLoginUsername($config["definition"]["repository"]["username"]);
            }
            if (isset($config["definition"]["repository"]["#password"])) {
                $this->setLoginPassword(
                    $this->getEncryptor()->decrypt($config["definition"]["repository"]["#password"])
                );
            }
            if (isset($config["definition"]["repository"]["server"])) {
                $this->setLoginServer($config["definition"]["repository"]["server"]);
            }
        }
        return $this;
    }
}
