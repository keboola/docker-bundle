<?php

namespace Keboola\DockerBundle\Docker\Image\Builder;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class ImageBuilder extends Image
{
    const COMMON_LABEL = 'com.keboola.docker.runner.origin=builder';
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
     * @var string
     */
    protected $containerId;

    /**
     * @var string
     */
    protected $fullImageId;

    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var string
     */
    private $parentType;

    /**
     * @var string
     */
    private $parentImage;

    public function __construct(ObjectEncryptor $encryptor, Component $component, LoggerInterface $logger)
    {
        parent::__construct($encryptor, $component, $logger);
        $config = $component->getImageDefinition();
        if (isset($config["build_options"])) {
            if (isset($config["build_options"]["repository"]["username"])) {
                $this->repoUsername = $config["build_options"]["repository"]["username"];
            }
            if (isset($config["build_options"]["repository"]["#password"])) {
                $this->repoPassword =
                    $this->getEncryptor()->decrypt($config["build_options"]["repository"]["#password"]);
            }
            $this->repository = $config["build_options"]["repository"]["uri"];
            $this->repositoryType = $config["build_options"]["repository"]["type"];
            $this->entryPoint = $config["build_options"]["entry_point"];
            $this->parentType = $config["build_options"]["parent_type"];
            $this->commands = [];
            if (!empty($config["build_options"]["commands"]) && is_array($config["build_options"]["commands"])) {
                foreach ($config["build_options"]["commands"] as $command) {
                    $this->commands[] = $command;
                }
            }
            $this->parameters = [];
            if (!empty($config["build_options"]["parameters"]) && is_array($config["build_options"]["parameters"])) {
                foreach ($config["build_options"]["parameters"] as $parameter) {
                    if (empty($parameter['name']) || empty($parameter['type']) || !isset($parameter['required'])) {
                        throw new BuildException("Invalid parameter definition: " . var_export($parameter, true));
                    }
                    $this->parameters[$parameter['name']] = new BuilderParameter(
                        $parameter['name'],
                        $parameter['type'],
                        $parameter['required'],
                        $parameter['default_value'],
                        empty($parameter['values']) ? [] : $parameter['values']
                    );
                }
            }
            if (isset($config["build_options"]["version"])) {
                $this->version = $config["build_options"]["version"];
            }
            if (isset($config["build_options"]["cache"])) {
                $this->cache = $config["build_options"]["cache"];
            }
        }
    }

    /**
     * @return string
     */
    public function getRepoUsername()
    {
        return $this->repoUsername;
    }

    /**
     * @return string
     */
    public function getRepoPassword()
    {
        return $this->repoPassword;
    }

    /**
     * @return string
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * @return string
     */
    public function getRepositoryType()
    {
        return $this->repositoryType;
    }

    /**
     * @return string
     */
    public function getEntryPoint()
    {
        return $this->entryPoint;
    }

    /**
     * @return array
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * @return BuilderParameter[]
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return bool
     */
    public function getCache()
    {
        return $this->cache;
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
            if (empty($parts['scheme'])) {
                throw new BuildParameterException("Invalid repository address: use https:// URL.");
            }
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
     */
    private function createDockerFile()
    {
        $dockerFile = '';
        $dockerFile .= "FROM " . $this->parentImage . "\n";
        $dockerFile .= "LABEL " . self::COMMON_LABEL . "\n";
        $dockerFile .= "WORKDIR /home\n";

        if ($this->getVersion()) {
            $dockerFile .= "\nENV APP_VERSION " . $this->getVersion();
        }
        if ($this->getRepositoryType() == 'git') {
            $repositoryCommands = $this->handleGitCredentials($this->temp->getTmpFolder());
        } else {
            throw new BuildParameterException("Repository type " . $this->getRepositoryType() . " cannot be handled.");
        }
        if (!$this->repository) {
            throw new BuildParameterException("Repository address must be supplied.");
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
        if (preg_match_all('#{{\#?[a-z0-9_-]+}}#i', $dockerFile, $matches)) {
            throw new BuildParameterException(
                "Orphaned parameters remaining in build commands " . implode(",", $matches[0])
            );
        }
        $this->logger->debug("Created dockerfile " . $dockerFile);
        file_put_contents($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile', $dockerFile);
    }


    /**
     * @param array $configData
     */
    private function initParameters(array $configData)
    {
        // set parameter values
        if (isset($configData['parameters']) && is_array($configData['parameters'])) {
            foreach ($configData['parameters'] as $key => $value) {
                // use only root elements of parameters
                if (isset($this->parameters[$key])) {
                    $this->parameters[$key]->setValue($value);
                    // handle special parameters
                }
            }
        }

        if (isset($configData['runtime']) && is_array($configData['runtime'])) {
            foreach ($configData['runtime'] as $key => $value) {
                // use only root elements of parameters
                if (isset($this->parameters[$key])) {
                    $this->parameters[$key]->setValue($value);
                    // handle special parameters - repository properties cannot be passed through normal parameters
                    if ($key === 'repository') {
                        $this->repository = $value;
                    } elseif ($key === 'version') {
                        $this->version = $value;
                        if ($this->getVersion() == 'master') {
                            $this->logger->debug("Using master branch, caching disabled.");
                            $this->cache = false;
                        }
                    } elseif ($key === 'username') {
                        $this->repoUsername = $value;
                        unset($this->parameters[$key]);
                    } elseif ($key === '#password') {
                        $this->repoPassword = $value;
                        unset($this->parameters[$key]);
                    } elseif ($key === 'network') {
                        $this->logger->info(
                            "Overriding image network configuration setting with runtime value $value."
                        );
                        $this->getSourceComponent()->setNetworkType($value);
                    }
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

    public function setTemp(Temp $temp)
    {
        $this->temp = $temp;
    }

    /**
     * Run docker login and docker pull in container, login/logout race conditions
     */
    protected function pullImage()
    {
        try {
            $component = $this->getSourceComponent()->changeType($this->parentType);
            $image = ImageFactory::getImage($this->encryptor, $this->logger, $component, $this->temp, $this->isMain());
            $image->setRetryLimits($this->retryMinInterval, $this->retryMaxInterval, $this->retryMaxAttempts);
            $image->prepare($this->configData);
            $this->parentImage = $image->getFullImageId();
        } catch (\Exception $e) {
            throw new BuildException(
                "Failed to pull parent image {$this->getImageId()}:{$this->getTag()}, error: " . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * @return string
     */
    public function getBuildCommand()
    {
        if (!$this->getCache()) {
            $noCache = ' --no-cache';
        } else {
            $noCache = '';
        }
        return "sudo docker build$noCache --tag=" . escapeshellarg($this->getFullImageId()) .
            " " . $this->temp->getTmpFolder();
    }

    public function buildImage()
    {
        $this->logger->debug("Building image");
        $process = Process::fromShellCommandline($this->getBuildCommand());
        // set some timeout to make sure that the parent image can be downloaded and Dockerfile can be built
        $process->setTimeout(3600);
        $process->run();
        if ($process->getExitCode() != 0) {
            /* string matching is used because currently it is not possible to have different exit codes for
                `docker build` It is either 0 for success or 1 for failure and the individual command exit codes
                are ignored. */
            $message = "Build failed (code: {$process->getExitCode()}): " .
                " {$process->getOutput()} / {$process->getErrorOutput()}";
            if (preg_match('#KBC::USER_ERR:(.*?)KBC::USER_ERR#', $message, $matches)) {
                $message = $matches[1];
                throw new BuildParameterException($message);
            } else {
                throw new BuildException($message);
            }
        }
    }

    /*
     * @inheritdoc
     */
    public function prepare(array $configData)
    {
        $this->initParameters($configData);
        parent::prepare($configData);
        $this->temp->initRunFolder();
        $this->createDockerFile();
        try {
            $this->buildImage();
        } catch (BuildParameterException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BuildException("Failed to build image: " . $e->getMessage(), $e);
        }
    }

    /**
     * @inheritdoc
     */
    public function getFullImageId()
    {
        if (!$this->fullImageId) {
            $this->fullImageId = uniqid('builder-');
        }
        return $this->fullImageId;
    }
}
