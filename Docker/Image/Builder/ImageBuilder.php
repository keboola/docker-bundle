<?php

namespace Keboola\DockerBundle\Docker\Image\Builder;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\Temp\Temp;
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
     * @var string
     */
    protected $repositoryType;

    /**
     * @var string
     */
    private $entryPoint;

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
     * @return string
     */
    public function getRepositoryType()
    {
        return $this->repositoryType;
    }

    /**
     * @param string $repositoryType
     * @return $this
     */
    public function setRepositoryType($repositoryType)
    {
        $this->repositoryType = $repositoryType;
        return $this;
    }


    /**
     * @return string
     */
    public function getEntryPoint()
    {
        return $this->entryPoint;
    }

    /**
     * @param string $entryPoint
     * @return $this
     */
    public function setEntryPoint($entryPoint)
    {
        $this->entryPoint = $entryPoint;
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


    /**
     * Inject git credentials to git repository into the image so that they don't need to be
     *  passed in URL or command line.
     * @param string $path Working directory path.
     * @return array Docker build commands which need to be executed.
     */
    private function handleGitCredentials($path)
    {
        // https://git-scm.com/docs/git-credential-store
        $fileName = '.git-credentials';
        $parts = parse_url($this->getRepository());
        $credentials =
            $parts['scheme'] . '://' .
            urlencode($this->getLoginUsername()) . ':' . urlencode($this->getLoginPassword()) . '@' .
            $parts['host'] .
            (!empty($parts['port']) ? ':' . $parts['port'] : '') .
            (!empty($parts['path']) ? $parts['path'] : '') .
            (!empty($parts['query']) ? '?' . $parts['query'] : '');
        $credentials .= "\n\n";
        file_put_contents($path . DIRECTORY_SEPARATOR . $fileName, $credentials);

        // https://git-scm.com/docs/gitcredentials
        // COPY source is from Dockerfile context, so no path should be added to the source
        $ret[] = "COPY " . $fileName . " /tmp/" . $fileName;
        $ret[] = "RUN git config --global credential.helper 'store --file=/tmp/.git-credentials'";
        return $ret;
    }


    /**
     * @param Container $container
     * @return string
     * @throws BuildException
     */
    public function prepare(Container $container)
    {
        try {
            $temp = new Temp('docker');
            $temp->initRunFolder();
            $workingFolder = $temp->getTmpFolder();
            $dockerFile = '';
            $dockerFile .= "FROM " . $this->getDockerHubImageId() . "\n";
            $dockerFile .= "WORKDIR /home\n";

            $dockerFile .= "\n# Repository initialization\n";
            if ($this->getRepositoryType() == 'git') {
                $repositoryCommands = $this->handleGitCredentials($workingFolder);
            } else {
                throw new BuildException("Repository type " . $this->getRepositoryType() . " cannot be handled.");
            }
            foreach ($repositoryCommands as $command) {
                $dockerFile .= $command . "\n";
            }

            $dockerFile .= "\n# Image definition commands\n";
            foreach ($this->getCommands() as $command) {
                $dockerFile .= "RUN " . $this->replacePlaceholders($command) . "\n";
            }

            $dockerFile .= "WORKDIR /data\n";
            $dockerFile .= "ENTRYPOINT " . $this->replacePlaceholders($this->getEntryPoint()) . "\n";
            file_put_contents($workingFolder . DIRECTORY_SEPARATOR . 'Dockerfile', $dockerFile);
            $tag = uniqid('builder-');
            $process = new Process("sudo docker build --tag=" . escapeshellarg($tag) . " " . $workingFolder);
            $process->run();
            if ($process->getExitCode() != 0) {
                $message = "Build failed (code: {$process->getExitCode()}): " .
                    " {$process->getOutput()} / {$process->getErrorOutput()}";
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
                $this->setLoginPassword($this->getEncryptor()->decrypt($config["definition"]["build_options"]["#password"]));
            }
            if (isset($config["definition"]["build_options"]["repository"])) {
                $this->setRepository($config["definition"]["build_options"]["repository"]);
            }
            if (isset($config["definition"]["build_options"]["repository_type"])) {
                $this->setRepositoryType($config["definition"]["build_options"]["repository_type"]);
            }
            if (isset($config["definition"]["build_options"]["entry_point"])) {
                $this->setEntryPoint($config["definition"]["build_options"]["entry_point"]);
            }
            if (isset($config["definition"]["build_options"]["commands"])) {
                $this->setCommands($config["definition"]["build_options"]["commands"]);
            }
        }
        return $this;
    }
}
