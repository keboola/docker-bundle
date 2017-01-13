<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Exception\WeirdException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Gelf\ServerFactory;
use Keboola\Syrup\Job\Exception\InitializationException;
use Monolog\Logger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;

class Container
{
    /**
     * Container ID
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
    protected $dataDir;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var int Docker CLI process timeout
     */
    protected $dockerCliTimeout = 120;

    /**
     * @var ContainerLogger
     */
    private $containerLogger;

    /**
     * @var string
     */
    private $commandToGetHostIp;

    /**
     * @var RunCommandOptions
     */
    private $runCommandOptions;

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return Image
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @param $containerId
     * @param Image $image
     * @param Logger $logger
     * @param ContainerLogger $containerLogger
     * @param string $dataDirectory
     * @param string $commandToGetHostIp
     * @param int $minLogPort
     * @param $maxLogPort
     * @param RunCommandOptions $runCommandOptions
     */
    public function __construct(
        $containerId,
        Image $image,
        Logger $logger,
        ContainerLogger $containerLogger,
        $dataDirectory,
        $commandToGetHostIp,
        $minLogPort,
        $maxLogPort,
        RunCommandOptions $runCommandOptions
    ) {
        $this->logger = $logger;
        $this->containerLogger = $containerLogger;
        $this->image = $image;
        $this->dataDir = $dataDirectory;
        $this->id = $containerId;
        $this->commandToGetHostIp = $commandToGetHostIp;
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
        $this->runCommandOptions = $runCommandOptions;
    }

    /**
     * @return string
     */
    public function getDataDir()
    {
        return $this->dataDir;
    }

    public function cleanUp()
    {
        // Check if container not running
        $process = new Process('sudo docker ps | grep ' . escapeshellarg($this->id) . ' | wc -l');
        $process->mustRun();
        if (trim($process->getOutput()) !== '0') {
            throw new UserException("Container '{$this->id}' already running.");
        }

        // Check old containers, delete if found
        $process = new Process('sudo docker ps -a | grep ' . escapeshellarg($this->id) . ' | wc -l');
        $process->mustRun();
        if (trim($process->getOutput()) !== '0') {
            $this->removeContainer($this->id);
        }
    }

    /**
     * @return Process
     * @throws ApplicationException
     */
    public function run()
    {
        $retries = 0;
        do {
            $retry = false;
            if ($retries > 0) {
                $this->id .= '.' . $retries;
            }

            $process = new Process($this->getRunCommand($this->id));
            $process->setTimeout(null);

            // create container
            $startTime = time();
            try {
                $this->logger->notice("Executing docker process {$this->getImage()->getFullImageId()}.");
                if ($this->getImage()->getSourceComponent()->getLoggerType() == 'gelf') {
                    $this->runWithLogger($process, $this->id);
                } else {
                    $this->runWithoutLogger($process);
                }
                $this->logger->notice("Docker process {$this->getImage()->getFullImageId()} finished.");

                if (!$process->isSuccessful()) {
                    $this->handleContainerFailure($process, $this->id, $startTime);
                }
            } catch (WeirdException $e) {
                $this->logger->notice("Phantom of the opera is here: " . $e->getMessage());
                sleep(random_int(1, 4));
                $retry = true;
                $retries++;
                if ($retries >= 5) {
                    $this->logger->notice("Weird error occurred too many times.");
                    throw new ApplicationException($e->getMessage(), $e);
                }
            } finally {
                try {
                    $this->removeContainer($this->id);
                } catch (ProcessFailedException $e) {
                    $this->logger->notice(
                        "Cannot remove container {$this->getImage()->getFullImageId()} {$this->id}: {$e->getMessage()}"
                    );
                    // continue
                }
            }
        } while ($retry);
        return $process;
    }

    private function runWithoutLogger(Process $process)
    {
        $process->run(function ($type, $buffer) {
            if (mb_strlen($buffer) > 64000) {
                $buffer = mb_substr($buffer, 0, 64000) . " [trimmed]";
            }
            if ($type === Process::ERR) {
                $this->containerLogger->error($buffer);
            } else {
                $this->containerLogger->info($buffer);
            }
        });
    }

    private function runWithLogger(Process $process, $containerName)
    {
        $server = ServerFactory::createServer($this->getImage()->getSourceComponent()->getLoggerServerType());
        $containerId = '';
        $server->start(
            $this->minLogPort,
            $this->maxLogPort,
            function ($port) use ($process, $containerName) {
                // get IP address of host from container
                $processIp = new Process($this->commandToGetHostIp);
                $processIp->mustRun();
                $hostIp = trim($processIp->getOutput());

                $this->runCommandOptions->setEnvironmentVariables(
                    array_merge(
                        $this->runCommandOptions->getEnvironmentVariables(),
                        ['KBC_LOGGER_ADDR' => $hostIp, 'KBC_LOGGER_PORT' => $port]
                    )
                );
                $process->setCommandLine($this->getRunCommand($containerName));
                $process->start();
            },
            function (&$terminated) use ($process) {
                if (!$process->isRunning()) {
                    $terminated = true;
                    if (trim($process->getOutput()) != '') {
                        $this->containerLogger->info($process->getOutput());
                    }
                    if (trim($process->getErrorOutput()) != '') {
                        $this->containerLogger->error($process->getErrorOutput());
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
                    $this->logger->notice("Invalid container host " . $event['host'], $event);
                } else {
                    $this->containerLogger->addRawRecord(
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
        try {
            $inspect = $this->inspectContainer($containerId);
        } catch (ProcessFailedException $e) {
            $this->logger->notice(
                "Cannot inspect container {$this->getImage()->getFullImageId()} '{$containerId}' on failure: " .
                $e->getMessage()
            );
            $inspect = [];
        }

        if (isset($inspect["State"]) &&
            isset($inspect["State"]["OOMKilled"]) && $inspect["State"]["OOMKilled"] === true
        ) {
            $data = [
                "container" => [
                    "id" => $this->getId()
                ]
            ];
            throw new OutOfMemoryException(
                "Component out of memory (exceeded {$this->getImage()->getSourceComponent()->getMemory()})",
                null,
                $data
            );
        }

        // killed containers
        if ($process->getExitCode() == 137) {
            // this catches the timeout from `sudo timeout`
            if ($duration >= $this->getImage()->getSourceComponent()->getProcessTimeout()) {
                throw new UserException(
                    "Running {$this->getImage()->getFullImageId()} container exceeded the timeout of " .
                    $this->getImage()->getSourceComponent()->getProcessTimeout() . " seconds."
                );
            } else {
                throw new InitializationException(
                    "{$this->getImage()->getFullImageId()} container terminated. Will restart."
                );
            }
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
                "id" => $this->getId(),
                "image" => $this->getImage()->getFullImageId()
            ];
            throw new UserException($message, null, $data);
        } else {
            if ((strpos($message, WeirdException::ERROR_DEV_MAPPER) !== false) ||
                (strpos($message, WeirdException::ERROR_DEVICE_RESUME) !== false)) {
                // in case of this weird docker error, throw a new exception to retry the container
                throw new WeirdException($message);
            } else {
                if ($this->getImage()->getSourceComponent()->isApplicationErrorDisabled()) {
                    throw new UserException(
                        "{$this->getImage()->getFullImageId()} container '{$this->getId()}' failed: ({$process->getExitCode()}) {$message}",
                        null,
                        $data
                    );
                } else {
                    // syrup will make sure that the actual exception message will be hidden to end-user
                    throw new ApplicationException(
                        "{$this->getImage()->getFullImageId()} container '{$this->getId()}' failed: ({$process->getExitCode()}) {$message}",
                        null,
                        $data
                    );
                }
            }
        }
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
            foreach ($this->runCommandOptions->getEnvironmentVariables() as $key => $value) {
                $envs .= " --env " . escapeshellarg($key) . "=" . str_replace(' ', '\\ ', escapeshellarg($value));
            }
            $command = "docker run";
        } else {
            $dataDir = $this->dataDir;
            foreach ($this->runCommandOptions->getEnvironmentVariables() as $key => $value) {
                $envs .= " --env \"" . str_replace('"', '\"', $key) . "=" . str_replace('"', '\"', $value). "\"";
            }
            $command = "sudo timeout --signal=SIGKILL {$this->getImage()->getSourceComponent()->getProcessTimeout()} docker run";
        }

        $labels = '';
        foreach ($this->runCommandOptions->getLabels() as $label) {
            $labels .= ' --label ' . escapeshellarg($label);
        }

        $command .= " --volume " . escapeshellarg($dataDir) . ":/data"
            . " --memory " . escapeshellarg($this->getImage()->getSourceComponent()->getMemory())
            . " --memory-swap " . escapeshellarg($this->getImage()->getSourceComponent()->getMemory())
            . " --cpu-shares " . escapeshellarg($this->getImage()->getSourceComponent()->getCpuShares())
            . " --net " . escapeshellarg($this->getImage()->getSourceComponent()->getNetworkType())
            . $envs
            . $labels
            . " --name " . escapeshellarg($containerId)
            . " " . escapeshellarg($this->getImage()->getFullImageId());
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
        $process->mustRun();
    }

    /**
     * @param $containerId
     * @return mixed
     */
    public function inspectContainer($containerId)
    {
        $process = new Process($this->getInspectCommand($containerId));
        $process->setTimeout($this->dockerCliTimeout);
        $process->mustRun();
        $inspect = json_decode($process->getOutput(), true);
        return array_pop($inspect);
    }
}
