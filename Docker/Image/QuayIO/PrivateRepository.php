<?php

namespace Keboola\DockerBundle\Docker\Image\QuayIO;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Symfony\Component\Process\Process;

class PrivateRepository extends Image\QuayIO
{
    protected $loginUsername;
    protected $loginPassword;
    protected $loginServer = "quay.io";

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
     * @inheritdoc
     */
    public function prepare(Container $container, array $configData, $containerId)
    {
        try {
            $process = new Process("sudo docker login {$this->getLoginParams()}");
            $process->run();
            if ($process->getExitCode() != 0) {
                $message = "Login failed (code: {$process->getExitCode()}): " .
                    "{$process->getOutput()} / {$process->getErrorOutput()}";
                throw new LoginFailedException($message);
            }
            $tag = parent::prepare($container, $configData, $containerId);
            return $tag;
        } finally {
            (new Process("sudo docker logout {$this->getLogoutParams()}"))->mustRun();
        }
    }

    /**
     * @param array $config
     * @return $this
     */
    public function fromArray($config = [])
    {
        parent::fromArray($config);
        if (isset($config["definition"]["repository"])) {
            if (isset($config["definition"]["repository"]["username"])) {
                $this->setLoginUsername($config["definition"]["repository"]["username"]);
            }
            if (isset($config["definition"]["repository"]["#password"])) {
                $this->setLoginPassword($this->getEncryptor()->decrypt($config["definition"]["repository"]["#password"]));
            }
        }
        return $this;
    }
}
