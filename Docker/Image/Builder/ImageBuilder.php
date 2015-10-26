<?php

namespace Keboola\DockerBundle\Docker\Image\Builder;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Logger;
use Symfony\Component\Process\Process;

class ImageBuilder extends Image\DockerHub\PrivateRepository
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $repoUsername;

    /**
     * @var string
     */
    protected $repoPassword;

    /**
     * @var string
     */
    protected $repository;

    /**
     * @var string
     */
    protected $repositoryType;

    /**
     * Dockerfile entrypoint.
     *
     * @var string
     */
    protected $entryPoint;

    /**
     * Dockerfile commands.
     *
     * @var array
     */
    protected $commands;

    /**
     * Parameters from component configuration which are used when generating Dockerfile.
     *
     * @var BuilderParameter[]
     */
    protected $parameters;

    /**
     * @var string Manually specified version of the image.
     */
    protected $version;

    /**
     * @var bool True if the application docker build is cached, false if it is not cached.
     */
    protected $cache = true;

    /**
     * Constructor
     * @param ObjectEncryptor $encryptor
     */
    public function __construct(ObjectEncryptor $encryptor)
    {
        parent::__construct($encryptor);
    }


    /**
     * @param Logger $log
     */
    public function setLogger(Logger $log)
    {
        $this->logger = $log;
    }


    /**
     * @return string
     */
    public function getRepoUsername()
    {
        return $this->repoUsername;
    }


    /**
     * @param string $repoUsername
     * @return $this
     */
    public function setRepoUsername($repoUsername)
    {
        $this->repoUsername = $repoUsername;
        return $this;
    }


    /**
     * @return string
     */
    public function getRepoPassword()
    {
        return $this->repoPassword;
    }


    /**
     * @param mixed $repoPassword
     * @return $this
     */
    public function setRepoPassword($repoPassword)
    {
        $this->repoPassword = $repoPassword;
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
     * @return BuilderParameter[]
     */
    public function getParameters()
    {
        return $this->parameters;
    }


    /**
     * @param array $parameters
     * @return $this
     */
    public function setParameters(array $parameters)
    {
        $this->parameters = [];
        foreach ($parameters as $parameter) {
            if (empty($parameter['name']) || empty($parameter['type']) || !isset($parameter['required'])) {
                throw new BuildException("Invalid parameter definition: " . var_export($parameter, true));
            }
            $this->parameters[$parameter['name']] = new BuilderParameter(
                $parameter['name'],
                $parameter['type'],
                $parameter['required'],
                empty($parameter['values']) ? [] : $parameter['values']
            );
        }

        return $this;
    }


    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }


    /**
     * @param string $version
     * @return $this
     */
    public function setVersion($version)
    {
        $this->version = $version;
        return $this;
    }


    /**
     * @return bool
     */
    public function getCache()
    {
        return $this->cache;
    }


    /**
     * @param bool $cache
     * @return $this
     */
    public function setCache($cache)
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Replace placeholders in a string.
     * @param string $string Arbitrary string
     * @return string
     */
    private function replacePlaceholders($string)
    {
        $result = $string;
        foreach ($this->parameters as $name => $parameter) {
            $result = preg_replace("#{{" . preg_quote($name, "#") . "}}#", $parameter->getValue(), $result);
        }
        return $result;
    }


    /**
     * Inject git credentials to git repository into the image so that they don't need to be
     *  passed in URL or command line.
     * @param string $path Working directory path.
     * @return array Docker build commands which need to be executed.
     */
    private function handleGitCredentials($path)
    {
        $ret = [];
        if ($this->getRepoUsername()) {
            // https://git-scm.com/docs/git-credential-store
            $fileName = '.git-credentials';
            $parts = parse_url($this->getRepository());
            $credentials =
                $parts['scheme'] . '://' .
                urlencode($this->getRepoUsername()) . ':' . urlencode($this->getRepoPassword()) . '@' .
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
        }
        return $ret;
    }


    /**
     * Create DockerFile file with the build instructions in the working folder.
     * @param string $workingFolder Working folder.
     */
    private function createDockerFile($workingFolder)
    {
        $dockerFile = '';
        $dockerFile .= "FROM " . $this->getDockerHubImageId() . "\n";
        $dockerFile .= "WORKDIR /home\n";

        if ($this->getVersion()) {
            $dockerFile .= "\n# Version " . $this->getVersion();
        }
        if ($this->getRepositoryType() == 'git') {
            $repositoryCommands = $this->handleGitCredentials($workingFolder);
        } else {
            throw new BuildException("Repository type " . $this->getRepositoryType() . " cannot be handled.");
        }
        if ($repositoryCommands) {
            $dockerFile .= "\n# Repository initialization\n";
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

        // verify that no placeholders remained in Dockerfile
        if (preg_match_all('#{{[a-z0-9_-]+}}#i', $dockerFile, $matches)) {
            throw new BuildParameterException(
                "Orphaned parameters remaining in build commands " . implode(",", $matches[0])
            );
        }
        file_put_contents($workingFolder . DIRECTORY_SEPARATOR . 'Dockerfile', $dockerFile);
    }


    /**
     * @param array $configData
     */
    private function initParameters(array $configData)
    {
        // set parameter values
        if (isset($configData['parameters']) && is_array($configData['parameters'])) {
            foreach ($configData['parameters'] as $key => $value) {
                // use only root elements of configData
                if (isset($this->parameters[$key])) {
                    $this->parameters[$key]->setValue($value);
                }
            }
        }

        // predefined parameters
        $this->parameters['repository'] = new BuilderParameter('repository', 'string', false);
        $this->parameters['repository']->setValue($this->getRepository());

        // verify required parameters
        foreach ($this->parameters as $parameter) {
            if (($parameter->getValue() === null) && $parameter->isRequired()) {
                throw new BuildParameterException(
                    "Parameter " . $parameter->getName() . " is required, but has no value."
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function prepare(Container $container, array $configData)
    {
        $this->initParameters($configData);
        try {
            if ($this->getLoginUsername()) {
                // Login to docker repository
                $process = new Process("sudo docker login {$this->getLoginParams()}");
                $process->run();
                if ($process->getExitCode() != 0) {
                    $message = "Login failed (code: {$process->getExitCode()}): " .
                        "{$process->getOutput()} / {$process->getErrorOutput()}";
                    throw new LoginFailedException($message);
                }
            }

            $temp = new Temp('docker');
            $temp->initRunFolder();
            $workingFolder = $temp->getTmpFolder();
            $this->createDockerFile($workingFolder);
            $tag = uniqid('builder-');
            if (!$this->getCache()) {
                $noCache = ' --no-cache';
            } else {
                $noCache = '';
            }
            $process = new Process("sudo docker build$noCache --tag=" . escapeshellarg($tag) . " " . $workingFolder);
            // set some timeout to make sure that the parent image can be downloaded and Dockerfile can be built
            $process->setTimeout(3600);
            $process->run();
            if ($process->getExitCode() != 0) {
                $message = "Build failed (code: {$process->getExitCode()}): " .
                    " {$process->getOutput()} / {$process->getErrorOutput()}";
                throw new BuildException($message);
            }
            return $tag;
        } catch (\Exception $e) {
            throw new BuildException("Failed to build image: " . $e->getMessage(), $e);
        } finally {
            if ($this->getLoginUsername()) {
                // Logout from docker repository
                (new Process("sudo docker logout {$this->getLogoutParams()}"))->run();
            }
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
            if (isset($config["definition"]["build_options"]["repository"]["username"])) {
                $this->setRepoUsername($config["definition"]["build_options"]["repository"]["username"]);
            }
            if (isset($config["definition"]["build_options"]["repository"]["#password"])) {
                $this->setRepoPassword(
                    $this->getEncryptor()->decrypt($config["definition"]["build_options"]["repository"]["#password"])
                );
            }
            $this->setRepository($config["definition"]["build_options"]["repository"]["uri"]);
            $this->setRepositoryType($config["definition"]["build_options"]["repository"]["type"]);
            $this->setEntryPoint($config["definition"]["build_options"]["entry_point"]);
            if (isset($config["definition"]["build_options"]["commands"])) {
                $this->setCommands($config["definition"]["build_options"]["commands"]);
            }
            if (isset($config["definition"]["build_options"]["parameters"])) {
                $this->setParameters($config["definition"]["build_options"]["parameters"]);
            }
            if (isset($config["definition"]["build_options"]["version"])) {
                $this->setVersion($config["definition"]["build_options"]["version"]);
            }
            if (isset($config["definition"]["build_options"]["cache"])) {
                $this->setCache($config["definition"]["build_options"]["cache"]);
            }
        }
        return $this;
    }
}
