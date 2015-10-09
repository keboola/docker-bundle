<?php

namespace Keboola\DockerBundle\Docker\Image\Builder;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Symfony\Component\Process\Process;

class ImageBuilder extends Image\DockerHub
{
    /**
     * @var string
     */
    protected $loginEmail;

    /**
     * @var string
     */
    protected $loginUsername;

    /**
     * @var string
     */
    protected $loginPassword;

    /**
     * @var string
     */
    protected $repository;

    /**
     * @var array
     */
    protected $commands;

    /**
     * @return string
     */
    public function getLoginEmail()
    {
        return $this->loginEmail;
    }

    /**
     * @param string $loginEmail
     * @return $this
     */
    public function setLoginEmail($loginEmail)
    {
        $this->loginEmail = $loginEmail;

        return $this;
    }

    /**
     * @return string
     */
    public function getLoginUsername()
    {
        return $this->loginUsername;
    }

    /**
     * @param string $loginUsername
     * @return $this
     */
    public function setLoginUsername($loginUsername)
    {
        $this->loginUsername = $loginUsername;

        return $this;
    }

    /**
     * @return string
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
     * @return string
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * @param string $repository
     * @return $this
     */
    public function setRepository($repository)
    {
        $this->repository = $repository;

        return $this;
    }

    /**
     * @return array
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * @param array $commands
     * @return $this
     */
    public function setCommands(array $commands)
    {
        $this->commands = [];
        foreach ($commands as $command) {
            $this->commands[] = $command;
        }

        return $this;
    }


    /**
     * Replace placeholders in a string.
     * @param string $string Arbitrary string
     * @return string
     */
    private function replacePlaceholders($string)
    {
        $string = preg_replace('#{{repository}}#', $this->getRepository(), $string);
        return $string;
    }


    private function getGitCredentialFile()
    {
        $fileName = '.git-credentials';
        $credentials = "url={$this->getRepository()}\n";
        $credentials .= "username={$this->getLoginUsername()}\n";
        $credentials .= "password={$this->getLoginPassword()}\n\n";
        file_put_contents($fileName, $credentials);
    }

    /**
     * @param Container $container
     * @return string
     * @throws BuildException
     */
    public function prepare(Container $container)
    {
        try {
            $dockerFile = '';
            $dockerFile .= $this->getDockerHubImageId() . "\n";
            $credentials = $this->getGitCredentialFile();
            $dockerFile .= 'COPY ' . $credentials . ' ~' . $credentials;
            foreach ($this->getCommands() as $command) {
                $dockerFile .= 'RUN ' . $this->replacePlaceholders($command) . "\n";
            }
            file_put_contents('Dockerfile', $dockerFile);
            $tag = escapeshellarg(uniqid('builder-'));
            $process = new Process("sudo docker --tag=$tag build .");
            $process->run();
            if ($process->getExitCode() != 0) {
                $message = "Build failed (code: {$process->getExitCode()}): {$process->getOutput()} / {$process->getErrorOutput()}";
                throw new BuildException($message);
            }
            return $tag;
        } catch (\Exception $e) {
            throw new BuildException("Failed to build image: " . $e->getMessage(), $e);
        }
    }


    /**
     * Set configuration from array.
     * @param array $config
     * @return $this
     */
    public function fromArray($config = [])
    {
        parent::fromArray($config);
        if (isset($config["definition"]["build_options"])) {
            if (isset($config["definition"]["build_options"]["email"])) {
                $this->setLoginEmail($config["definition"]["build_options"]["email"]);
            }
            if (isset($config["definition"]["build_options"]["username"])) {
                $this->setLoginUsername($config["definition"]["build_options"]["username"]);
            }
            if (isset($config["definition"]["build_options"]["#password"])) {
                $this->setLoginPassword($config["definition"]["build_options"]["#password"]);
            }
            if (isset($config["definition"]["build_options"]["repository"])) {
                $this->setRepository($config["definition"]["build_options"]["repository"]);
            }
            if (isset($config["definition"]["build_options"]["commands"])) {
                $this->setCommands($config["definition"]["build_options"]["commands"]);
            }
        }
        return $this;
    }


}