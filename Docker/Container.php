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
     * @var int Docker CLI process timeout
     */
    protected $dockerCliTimeout = 120;

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

        // Run container
        $process = new Process($this->getRunCommand($containerId));
        $process->setTimeout($this->getImage()->getProcessTimeout() + 1);
        $startTime = time();
        try {
            $this->log->debug("Executing docker process.");
            if ($this->getImage()->isStreamingLogs()) {
                $process->run(function ($type, $buffer) {
                    if (mb_strlen($buffer) > 64000) {
                        $buffer = mb_substr($buffer, 0, 64000) . " [trimmed]";
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
            // is actually not working
            $this->removeContainer($containerId);
            throw new UserException(
                "Running container exceeded the timeout of {$this->getImage()->getProcessTimeout()} seconds."
            );
        }
        $duration = time() - $startTime;

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

            // this catches the timeout from `sudo timeout`
            if ($process->getExitCode() == 137 && $duration >= $this->getImage()->getProcessTimeout()) {
                throw new UserException(
                    "Running container exceeded the timeout of {$this->getImage()->getProcessTimeout()} seconds."
                );
            }

            $message = $process->getErrorOutput();
            if (!$message) {
                $message = $process->getOutput();
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
        $dataDir = $this->dataDir;
        foreach ($this->getEnvironmentVariables() as $key => $value) {
            $envs .= " -e \"" . str_replace('"', '\"', $key) . "=" . str_replace('"', '\"', $value). "\"";
        }

        $command = "sudo timeout --signal=SIGKILL {$this->getImage()->getProcessTimeout()} sudo docker run";

        $command .= " --volume=" . escapeshellarg($dataDir) . ":/data"
            . " --memory=" . escapeshellarg($this->getImage()->getMemory())
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
