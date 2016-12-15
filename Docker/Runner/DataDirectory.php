<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Exception\WeirdException;
use Keboola\Syrup\Exception\ApplicationException;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class DataDirectory
{
    /**
     * @var string
     */
    private $workingDir;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * DataDirectory constructor.
     * @param string $workingDir
     * @param Logger $logger
     */
    public function __construct($workingDir, Logger $logger)
    {
        $this->workingDir = $workingDir;
        $this->logger = $logger;
    }

    /**
     *
     */
    public function createDataDir()
    {
        $fs = new Filesystem();
        $fs->mkdir($this->workingDir);

        $structure = [
            $this->workingDir . "/data",
            $this->workingDir . "/data/in",
            $this->workingDir . "/data/in/tables",
            $this->workingDir . "/data/in/files",
            $this->workingDir . "/data/in/user",
            $this->workingDir . "/data/out",
            $this->workingDir . "/data/out/tables",
            $this->workingDir . "/data/out/files"
        ];

        $fs->mkdir($structure);
    }

    public function getNormalizeCommand()
    {
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        return "sudo docker run --volume=" .
            $this->workingDir . DIRECTORY_SEPARATOR . "data:/data alpine sh -c 'chown {$uid} /data -R'";
    }

    public function normalizePermissions()
    {
        $retries = 0;
        do {
            $retry = false;
            $command = $this->getNormalizeCommand();
            $process = new Process($command);
            $process->setTimeout(60);
            try {
                $process->run();
                if ($process->getExitCode() != 1) {
                    $message = $process->getOutput() . $process->getErrorOutput();
                    if ((strpos($message, WeirdException::ERROR_DEV_MAPPER) !== false) ||
                        (strpos($message, WeirdException::ERROR_DEVICE_RESUME) !== false)) {
                        // in case of this weird docker error, throw a new exception to retry the container
                        throw new WeirdException($message);
                    }
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
            }
        } while ($retry);
    }

    public function getDataDir()
    {
        return $this->workingDir . "/data";
    }

    public function dropDataDir()
    {
        $this->normalizePermissions();
        $fs = new Filesystem();
        $finder = new Finder();
        $finder->files()->in($this->workingDir . DIRECTORY_SEPARATOR . 'data');
        $fs->remove($finder);
        $fs->remove($this->workingDir . DIRECTORY_SEPARATOR . 'data');
    }

    public function moveOutputToInput()
    {
        // delete input
        $fs = new Filesystem();
        $structure = [
            $this->workingDir . "/data/in/tables",
            $this->workingDir . "/data/in/files",
            $this->workingDir . "/data/in/user",
            $this->workingDir . "/data/in",
        ];
        $finder = new Finder();
        $finder->files()->in($structure);
        $fs->remove($finder);
        $fs->remove($structure);

        // rename
        $fs->rename(
            $this->workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'out',
            $this->workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'in'
        );

        // create empty output
        $fs = new Filesystem();
        $fs->mkdir($this->workingDir);

        $structure = [
            $this->workingDir . "/data/out",
            $this->workingDir . "/data/out/tables",
            $this->workingDir . "/data/out/files",
            $this->workingDir . "/data/in/user",
        ];

        $fs->mkdir($structure);
    }
}
