<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Gelf\ServerFactory;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
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
     * @var int Docker CLI process timeout
     */
    protected $dockerCliTimeout = 120;

    /**
     * @var ContainerLogger
     */
    private $containerLog;

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
     * @param ContainerLogger $containerLogger
     */
    public function __construct(Image $image, Logger $logger, ContainerLogger $containerLogger)
    {
        $this->log = $logger;
        $this->containerLog = $containerLogger;
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
     * @param array $configData Configuration (same as the one stored in data config file)
     * @return Process
     * @throws ApplicationException
     */
    public function run($containerId, array $configData)
    {
        if (!$this->getDataDir()) {
            throw new ApplicationException("Data directory not set.");
        }

        $this->getImage()->prepare($this, $configData, $containerId);
        $this->setId($this->getImage()->getFullImageId());

        $process = new Process($this->getRunCommand($containerId));
        $process->setTimeout(null);

        // create container
        $startTime = time();
        try {
            $this->log->debug("Executing docker process.");
            if ($this->getImage()->getLoggerType() == 'gelf') {
                $this->runWithLogger($process, $containerId);
            } else {
                $this->runWithoutLogger($process);
            }
            $this->log->debug("Docker process finished.");

            if (!$process->isSuccessful()) {
                $this->handleContainerFailure($process, $containerId, $startTime);
            }
        } finally {
            $this->removeContainer($containerId);
        }
        return $process;
    }

    private function runWithoutLogger(Process $process)
    {
        $process->run(function ($type, $buffer) {
            if (mb_strlen($buffer) > 64000) {
                $buffer = mb_substr($buffer, 0, 64000) . " [trimmed]";
            }
            if ($type === Process::ERR) {
                $this->containerLog->error($buffer);
            } else {
                $this->containerLog->info($buffer);
            }
        });
    }

    private function runWithLogger(Process $process, $containerName)
    {
        $server = ServerFactory::createServer($this->getImage()->getLoggerServerType());
        /* the port range is rather arbitrary, it intentionally excludes the default port (12201)
            to avoid mis-configured clients. */
        $containerId = '';
        $server->start(
            12202,
            13202,
            function ($port) use ($process, $containerName) {
                // get IP address of host
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $processIp = new Process('hostnamei');
                } else {
                    $processIp = new Process('ip -4 addr show docker0 | grep -Po \'inet \K[\d.]+\'');
                }
                $processIp->mustRun();
                $hostIp = trim($processIp->getOutput());

                $this->setEnvironmentVariables(array_merge(
                    $this->getEnvironmentVariables(),
                    ['KBC_LOGGER_ADDR' => $hostIp, 'KBC_LOGGER_PORT' => $port]
                ));
                $process->setCommandLine($this->getRunCommand($containerName));
                $process->start();
            },
            function (&$terminated) use ($process) {
                if (!$process->isRunning()) {
                    $terminated = true;
                    if (trim($process->getOutput()) != '') {
                        $this->containerLog->info($process->getOutput());
                    }
                    if (trim($process->getErrorOutput()) != '') {
                        $this->containerLog->error($process->getErrorOutput());
                    }
                }
            },
            function ($event) use ($containerName, &$containerId) {
                if (!$containerId) {
                    $inspect = $this->inspectContainer($containerName);
                    $containerId = $inspect['Id'];
                }
                // host is shortened containerId
                if ($event['host'] != substr($containerId, 0, strlen($event['host']))) {
                    $this->log->error("Invalid container host " . $event['host'], $event);
                } else {
                    $this->containerLog->addRawRecord(
                        $event['level'],
                        $event['timestamp'],
                        $event['short_message'],
                        $event
                    );
                }
            }
        );
    }


    private function handleContainerFailure(Process $process, $containerId, $startTime)
    {
        $duration = time() - $startTime;
        $inspect = $this->inspectContainer($containerId);

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

        // this catches the timeout from `sudo timeout`
        if ($process->getExitCode() == 137 && $duration >= $this->getImage()->getProcessTimeout()) {
            throw new UserException(
                "Running container exceeded the timeout of {$this->getImage()->getProcessTimeout()} seconds."
            );
        }

        $message = trim($process->getErrorOutput());
        if (!$message) {
            $message = trim($process->getOutput());
        }
        if (!$message) {
            $message = "No error message.";
        }

        // make the exception message very short
        if (mb_strlen($message) > 255) {
            $message = mb_substr($message, 0, 125) . " ... " . mb_substr($message, -125);
        }

        // put the whole message to exception data, but make sure not use too much memory
        $data = [
            "output" => mb_substr($process->getOutput(), -1000000),
            "errorOutput" => mb_substr($process->getErrorOutput(), -1000000)
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
            $command = "sudo timeout --signal=SIGKILL {$this->getImage()->getProcessTimeout()} docker run";
        }

        $command .= " --volume=" . escapeshellarg($dataDir) . ":/data"
            . " --memory=" . escapeshellarg($this->getImage()->getMemory())
            . " --memory-swap=" . escapeshellarg($this->getImage()->getMemory())
            . " --cpu-shares=" . escapeshellarg($this->getImage()->getCpuShares())
            . " --net=" . escapeshellarg($this->getImage()->getNetworkType())
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
        $command = "sudo docker rm -f ";
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
        $process->setTimeout($this->dockerCliTimeout);
        $process->run();
    }

    /**
     * @param $containerId
     * @return mixed
     */
    public function inspectContainer($containerId)
    {
        $process = new Process($this->getInspectCommand($containerId));
        $process->setTimeout($this->dockerCliTimeout);
        $process->run();
        $inspect = json_decode($process->getOutput(), true);
        return array_pop($inspect);
    }
}
