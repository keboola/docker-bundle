<?php

namespace Keboola\DockerBundle\Docker\Image\DockerHub;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class PrivateRepository extends Image\DockerHub
{
    protected $loginUsername;
    protected $loginPassword;
    protected $loginServer;

    public function __construct(ObjectEncryptor $encryptor, Component $component, LoggerInterface $logger)
    {
        parent::__construct($encryptor, $component, $logger);
        $config = $component->getImageDefinition();
        if (isset($config["repository"])) {
            if (isset($config["repository"]["username"])) {
                $this->loginUsername = $config["repository"]["username"];
            }
            if (isset($config["repository"]["#password"])) {
                $this->loginPassword = $this->getEncryptor()->decrypt($config["repository"]["#password"]);
            }
            if (isset($config["repository"]["server"])) {
                $this->loginServer = $config["repository"]["server"];
            }
        }
    }

    /**
     * @return string
     */
    public function getLoginUsername()
    {
        return $this->loginUsername;
    }

    /**
     * @return string
     */
    public function getLoginPassword()
    {
        return $this->loginPassword;
    }

    /**
     * @return string
     */
    public function getLoginServer()
    {
        return $this->loginServer;
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
        $process = new Process($command);
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
            $this->logImageHash();
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
}
