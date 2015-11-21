<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;

class Container
{
    /**
     *
     * Image Id
     *
     * @var string
     */
    protected $id;

    /**
     * @var Image
     */
    protected $image;

    /**
     * @var string
     */
    protected $version = 'latest';

    /**
     * @var string
     */
    protected $dataDir;

    /**
     * @var array
     */
    protected $environmentVariables = array();

    /**
     * @var Logger
     */
    private $log;

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return Image
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @param Image $image
     * @return $this
     */
    public function setImage($image)
    {
        $this->image = $image;
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
     * @param Image $image
     * @param Logger $logger
     */
    public function __construct(Image $image, Logger $logger)
    {
        $this->log = $logger;
        $this->setImage($image);
    }

    /**
     * @return string
     */
    public function getDataDir()
    {
        return $this->dataDir;
    }

    /**
     * @param string $dataDir
     * @return $this
     */
    public function setDataDir($dataDir)
    {
        $this->dataDir = $dataDir;
        return $this;
    }

    /**
     * @return array
     */
    public function getEnvironmentVariables()
    {
        return $this->environmentVariables;
    }

    /**
     * @param array $environmentVariables
     * @return $this
     */
    public function setEnvironmentVariables($environmentVariables)
    {
        $this->environmentVariables = $environmentVariables;
        return $this;
    }


    /**
     * @param string $containerId container id
     * @param array $configData Configuration (user supplied configuration stored in data config file)
     * @param array $volatileConfigData Configuration (user supplied configuration NOT stored in data config file)
     * @return Process
     * @throws ApplicationException
     */
    public function run($containerId, array $configData, array $volatileConfigData)
    {
        if (!$this->getDataDir()) {
            throw new ApplicationException("Data directory not set.");
        }

        $this->getImage()->prepare($this, $configData, $volatileConfigData, $containerId);
        $this->setId($this->getImage()->getFullImageId());

        // Run container
        $process = new Process($this->getRunCommand($containerId));
        $process->setTimeout($this->getImage()->getProcessTimeout());

        try {
            $this->log->debug("Executing docker process.");
            if ($this->getImage()->isStreamingLogs()) {
                $process->run(function ($type, $buffer) {
                    if (strlen($buffer) > 65536) {
                        $buffer = substr($buffer, 0, 65536) . " [trimmed]";
                    }
                    if ($type === Process::ERR) {
                        $this->log->error($buffer);
                    } else {
                        $this->log->info($buffer);
                    }
                });
            } else {
                $process->run();
            }
            $this->log->debug("Docker process finished.");
        } catch (ProcessTimedOutException $e) {
            $this->removeContainer($containerId);
            throw new UserException(
                "Running container exceeded the timeout of {$this->getImage()->getProcessTimeout()} seconds."
            );
        }

        if (!$process->isSuccessful()) {
            $inspect = $this->inspectContainer($containerId);
            $this->removeContainer($containerId);

            if (isset($inspect["State"]) && isset($inspect["State"]["OOMKilled"]) && $inspect["State"]["OOMKilled"] === true) {
                $data = [
                    "container" => [
                        "id" => $this->getId()
                    ]
                ];
                throw new OutOfMemoryException(
                    "Out of memory (exceeded {$this->getImage()->getMemory()})",
                    null,
                    $data
                );
            }

            $message = $process->getErrorOutput();
            if (!$message) {
                $message = $process->getOutput();
            }
            if (!$message) {
                $message = "No error message.";
            }
            if (strlen($message) > 255) {
                $message = substr($message, 0, 125) . " ... " . substr($message, -125);
            }
            $data = [
                "output" => substr($process->getOutput(), -1048576),
                "errorOutput" => substr($process->getErrorOutput(), -1048576)
            ];

            if ($process->getExitCode() == 1) {
                $data["container"] = [
                    "id" => $this->getId()
                ];
                throw new UserException($message, null, $data);
            } else {
                // syrup will make sure that the actual exception message will be hidden to end-user
                throw new ApplicationException(
                    "Container '{$this->getId()}' failed: ({$process->getExitCode()}) {$message}",
                    null,
                    $data
                );
            }
        }
        $this->removeContainer($containerId);
        return $process;
    }

    /**
     * @param $root
     * @return $this
     */
    public function createDataDir($root)
    {
        $fs = new Filesystem();
        $structure = array(
            $root . "/data",
            $root . "/data/in",
            $root . "/data/in/tables",
            $root . "/data/in/files",
            $root . "/data/in/user",
            $root . "/data/out",
            $root . "/data/out/tables",
            $root . "/data/out/files"
        );

        $fs->mkdir($structure);
        $this->setDataDir($root . "/data");
        return $this;
    }

    /**
     * Remove whole directory structure
     */
    public function dropDataDir()
    {
        $fs = new Filesystem();
        $structure = array(
            $this->getDataDir() . "/in/tables",
            $this->getDataDir() . "/in/files",
            $this->getDataDir() . "/in/user",
            $this->getDataDir() . "/in",
            $this->getDataDir() . "/out/files",
            $this->getDataDir() . "/out/tables",
            $this->getDataDir() . "/out",
            $this->getDataDir()
        );
        $finder = new Finder();
        $finder->files()->in($structure);
        $fs->remove($finder);
        $fs->remove($structure);
    }

    /**
     * @param string $containerId
     * @return string
     */
    public function getRunCommand($containerId)
    {
        setlocale(LC_CTYPE, "en_US.UTF-8");
        $envs = "";
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $dataDir = str_replace(DIRECTORY_SEPARATOR, '/', str_replace(':', '', '/' . lcfirst($this->dataDir)));
            foreach ($this->getEnvironmentVariables() as $key => $value) {
                $envs .= " -e " . escapeshellarg($key) . "=" . str_replace(' ', '\\ ', escapeshellarg($value));
            }
            $command = "docker run";
        } else {
            $dataDir = $this->dataDir;
            foreach ($this->getEnvironmentVariables() as $key => $value) {
                $envs .= " -e \"" . str_replace('"', '\"', $key) . "=" . str_replace('"', '\"', $value). "\"";
            }
            $command = "sudo docker run";
        }

        $command .= " --volume=" . escapeshellarg($dataDir) . ":/data"
            . " --memory=" . escapeshellarg($this->getImage()->getMemory())
            . " --cpu-shares=" . escapeshellarg($this->getImage()->getCpuShares())
            . $envs
            . " --name=" . escapeshellarg($containerId)
            . " " . escapeshellarg($this->getId());
        return $command;
    }

    /**
     * @param string $containerId
     * @return string
     */
    public function getRemoveCommand($containerId)
    {
        setlocale(LC_CTYPE, "en_US.UTF-8");
        $command = "sudo docker rm ";
        $command .= escapeshellarg($containerId);
        return $command;
    }

    /**
     * @param string $containerId
     * @return string
     */
    public function getInspectCommand($containerId)
    {
        setlocale(LC_CTYPE, "en_US.UTF-8");
        $command = "sudo docker inspect ";
        $command .= escapeshellarg($containerId);
        return $command;
    }

    /**
     * @param $containerId
     */
    public function removeContainer($containerId)
    {
        $process = new Process($this->getRemoveCommand($containerId));
        $process->run();
    }

    /**
     * @param $containerId
     * @return mixed
     */
    public function inspectContainer($containerId)
    {
        $process = new Process($this->getInspectCommand($containerId));
        $process->run();
        $inspect = json_decode($process->getOutput(), true);
        return array_pop($inspect);
    }
}
