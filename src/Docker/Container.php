<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\InitializationException;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Gelf\ServerFactory;
use Monolog\Logger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Keboola\DockerBundle\Docker\Container\Process;

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
     * @var string
     */
    protected $tmpDir;

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
     * @var int
     */
    private $minLogPort;

    /**
     * @var int
     */
    private $maxLogPort;

    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;

    /**
     * @var Limits
     */
    private $limits;

    /**
     * @var string
     */
    private $lastError;

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
     * @param string $tmpDirectory
     * @param string $commandToGetHostIp
     * @param int $minLogPort
     * @param $maxLogPort
     * @param RunCommandOptions $runCommandOptions
     * @param OutputFilterInterface $outputFilter
     * @param Limits $limits
     */
    public function __construct(
        $containerId,
        Image $image,
        Logger $logger,
        ContainerLogger $containerLogger,
        $dataDirectory,
        $tmpDirectory,
        $commandToGetHostIp,
        $minLogPort,
        $maxLogPort,
        RunCommandOptions $runCommandOptions,
        OutputFilterInterface $outputFilter,
        Limits $limits
    ) {
        $this->logger = $logger;
        $this->containerLogger = $containerLogger;
        $this->image = $image;
        $this->dataDir = $dataDirectory;
        $this->tmpDir = $tmpDirectory;
        $this->id = $containerId;
        $this->commandToGetHostIp = $commandToGetHostIp;
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
        $this->runCommandOptions = $runCommandOptions;
        $this->outputFilter = $outputFilter;
        $this->limits = $limits;
    }

    /**
     *
     */
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
        $process = new Process($this->getRunCommand($this->id));
        $process->setOutputFilter($this->outputFilter);
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

            $this->checkOOM($this->inspectContainer($this->id));
            if (!$process->isSuccessful()) {
                $this->handleContainerFailure($process, $startTime);
            } else {
                if ($process->getErrorOutput()) {
                    $buffer = $process->getErrorOutput();
                    if (mb_strlen($buffer) > 64000) {
                        $buffer = mb_substr($buffer, 0, 64000) . " [trimmed]";
                    }
                    $this->containerLogger->error($buffer);
                }
            }
        } finally {
            try {
                $this->removeContainer($this->id);
            } catch (ProcessFailedException $e) {
                $this->logger->notice(
                    "Cannot remove container {$this->getImage()->getFullImageId()} {$this->id}: {$e->getMessage()}"
                );
                // continue
            } catch (ProcessTimedOutException $e) {
                $this->logger->notice(
                    "Cannot remove container {$this->getImage()->getFullImageId()} {$this->id}: {$e->getMessage()}"
                );
                // continue
            }
        }
        return $process;
    }

    /**
     * @param Process $process
     */
    private function runWithoutLogger(Process $process)
    {
        $process->run(function ($type, $buffer) {
            if (mb_strlen($buffer) > 64000) {
                $buffer = mb_substr($buffer, 0, 64000) . " [trimmed]";
            }
            if ($type === Process::OUT) {
                $this->containerLogger->info($buffer);
            }
        });
    }

    /**
     * @param Process $process
     * @param $containerName
     */
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
                if (empty($event['host'])) {
                    throw new ApplicationException('Host parameter is missing from GELF message.');
                }
                if ($event['host'] != substr($containerId, 0, strlen($event['host']))) {
                    $this->logger->notice("Invalid container host " . $event['host'], $event);
                } else {
                    array_walk_recursive($event, function (&$value) {
                        $value = $this->outputFilter->filter($value);
                    });
                    $this->containerLogger->addRawRecord(
                        $event['level'],
                        $event['timestamp'],
                        $event['short_message'],
                        $event
                    );
                    if ($event['level'] <= 4) {
                        $this->lastError = $event['short_message'];
                    }
                }
            },
            null,
            function ($event) {
                $this->containerLogger->error("Invalid message: " . $event);
            }
        );
    }

    /**
     * @param Process $process
     * @param $startTime
     */
    private function handleContainerFailure(Process $process, $startTime)
    {
        $duration = time() - $startTime;
        $errorOutput = $process->getErrorOutput();
        $output = $process->getOutput();

        $message = $errorOutput;
        if (!$message) {
            $message = $output;
        }
        if (!$message) {
            $message = $this->lastError;
        }
        if (!$message) {
            $message = "No error message.";
        }

        // make the exception message reasonably short
        if (mb_strlen($message) > 4000) {
            $message = mb_substr($message, 0, 2000) . " ... " . mb_substr($message, -2000);
        }

        // put the whole message to exception data, but make sure not use too much memory
        $data = [
            "output" => mb_substr($output, -1000000),
            "errorOutput" => mb_substr($errorOutput, -1000000),
            "container" => [
                "id" => $this->getId(),
                "image" => $this->getImage()->getPrintableImageId(),
            ],
        ];

        // killed container or docker socket not available (instance termination)
        if (in_array($process->getExitCode(), [137, 125])) {
            // this catches the timeout from `sudo timeout`
            if ($duration >= $this->getImage()->getSourceComponent()->getProcessTimeout()) {
                throw new UserException(
                    "Running {$this->getImage()->getPrintableImageId()} container exceeded the timeout of " .
                    $this->getImage()->getSourceComponent()->getProcessTimeout() . " seconds.",
                    null,
                    $data
                );
            } else {
                throw new InitializationException(
                    "{$this->getImage()->getPrintableImageId()} container terminated. Will restart."
                );
            }
        } elseif ($process->getExitCode() == 1) {
            // syrup will log the process error output as part of the exception body
            throw new UserException($message, null, $data);
        } else {
            if ($this->getImage()->getSourceComponent()->isApplicationErrorDisabled()) {
                // syrup will log the process error output as part of the exception body
                throw new UserException(
                    "{$this->getImage()->getPrintableImageId()} container '{$this->getId()}' failed: ({$process->getExitCode()}) {$message}",
                    null,
                    $data
                );
            } else {
                // syrup will log the process error output as part of the exception body
                throw new ApplicationException(
                    "{$this->getImage()->getPrintableImageId()} container '{$this->getId()}' failed: ({$process->getExitCode()}) {$message}",
                    null,
                    $data
                );
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
        foreach ($this->runCommandOptions->getEnvironmentVariables() as $key => $value) {
            $envs .= " --env \"" . str_replace('"', '\"', $key) . "=" . str_replace('"', '\"', $value). "\"";
        }
        $command = "sudo timeout --signal=SIGKILL {$this->getImage()->getSourceComponent()->getProcessTimeout()} docker run";

        $labels = '';
        foreach ($this->runCommandOptions->getLabels() as $label) {
            $labels .= ' --label ' . escapeshellarg($label);
        }

        $command .= " --volume " . escapeshellarg($this->dataDir . ":/data")
            . " --volume " . escapeshellarg($this->tmpDir . ":/tmp")
            . " --memory " . escapeshellarg($this->limits->getMemoryLimit($this->getImage()))
            . ($this->getImage()->getSourceComponent()->hasNoSwap() ? " --memory-swap " . escapeshellarg($this->limits->getMemorySwapLimit($this->getImage())) : "")
            . " --net " . escapeshellarg($this->limits->getNetworkLimit($this->getImage()))
            . " --cpus " . escapeshellarg($this->limits->getCpuLimit($this->getImage()))
            . $envs
            . $labels
            . " --name " . escapeshellarg($containerId)
            . (!$this->getImage()->getSourceComponent()->runAsRoot() ? ' --user $(id -u):$(id -g)' : "")
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
     * @return array
     */
    public function inspectContainer($containerId)
    {
        try {
            $process = new Process($this->getInspectCommand($containerId));
            $process->setTimeout($this->dockerCliTimeout);
            $process->mustRun();
            $inspect = json_decode($process->getOutput(), true);
            $inspect = array_pop($inspect);
        } catch (ProcessFailedException $e) {
            $this->logger->notice(
                "Cannot inspect container {$this->getImage()->getFullImageId()} '{$containerId}' on failure: " .
                $e->getMessage()
            );
            $inspect = [];
        } catch (ProcessTimedOutException $e) {
            $this->logger->notice(
                "Cannot inspect container {$this->getImage()->getFullImageId()} '{$containerId}' on failure: " .
                $e->getMessage()
            );
            $inspect = [];
        }
        return $inspect;
    }

    /**
     * @param array $inspect Container inspect data
     * @throws OutOfMemoryException In case OOM was triggered during container run.
     */
    private function checkOOM(array $inspect)
    {
        if (isset($inspect["State"]["OOMKilled"]) && $inspect["State"]["OOMKilled"] === true) {
            $data = [
                "container" => [
                    "id" => $this->getId()
                ]
            ];
            throw new OutOfMemoryException(
                "Component out of memory (exceeded {$this->limits->getMemoryLimit($this->getImage())})",
                null,
                $data
            );
        }
    }
}
