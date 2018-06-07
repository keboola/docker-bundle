<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\Syrup\Job\Exception\InitializationException;
use Psr\Log\LoggerInterface;
use Retry\BackOff\UniformRandomBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class WorkingDirectory
{
    /**
     * @var string
     */
    private $workingDir;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * DataDirectory constructor.
     * @param string $workingDir
     * @param LoggerInterface $logger
     */
    public function __construct($workingDir, LoggerInterface $logger)
    {
        $this->workingDir = $workingDir;
        $this->logger = $logger;
    }

    public function createWorkingDir()
    {
        $fs = new Filesystem();
        $fs->mkdir($this->workingDir);

        $structure = [
            $this->workingDir . "/tmp",
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
        return "sudo docker run --rm --volume=" .
            $this->workingDir . ":/data alpine sh -c 'chown {$uid} /data -R && "
                . "chmod -R u+wrX /data'";
    }

    public function normalizePermissions()
    {
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new UniformRandomBackOffPolicy(60000, 180000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
        try {
            $proxy->call(
                function () use (&$process) {
                    $this->logger->notice("Normalizing working directory permissions");
                    $command = $this->getNormalizeCommand();
                    $process = new Process($command);
                    $process->setTimeout(120);
                    $process->run();
                }
            );
        } catch (ProcessTimedOutException $e) {
            throw new InitializationException(
                "Could not normalize permissions. Job will restart."
            );
        }
    }

    public function getDataDir()
    {
        return $this->workingDir . "/data";
    }

    public function getTmpDir()
    {
        return $this->workingDir . "/tmp";
    }

    public function dropWorkingDir()
    {
        $fs = new Filesystem();
        $finder = new Finder();
        $finder->files()->in($this->workingDir . DIRECTORY_SEPARATOR . 'data');
        $fs->remove($finder);
        $fs->remove($this->workingDir . DIRECTORY_SEPARATOR . 'data');
        $finder = new Finder();
        $finder->files()->in($this->workingDir . DIRECTORY_SEPARATOR . 'tmp');
        $fs->remove($finder);
        $fs->remove($this->workingDir . DIRECTORY_SEPARATOR . 'tmp');
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
            $this->workingDir . "/tmp",
        ];
        $finder = new Finder();
        $finder->files()->in($structure);
        $fs->remove($finder);
        $fs->remove($structure);

        // delete state file
        $fs->remove($this->workingDir . "/data/out/state.json");
        $fs->remove($this->workingDir . "/data/out/state.yml");

        // rename
        $fs->rename(
            $this->workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'out',
            $this->workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'in'
        );

        // create empty output
        $fs = new Filesystem();
        $fs->mkdir($this->workingDir);

        $structure = [
            $this->workingDir . "/tmp",
            $this->workingDir . "/data/out",
            $this->workingDir . "/data/out/tables",
            $this->workingDir . "/data/out/files",
            $this->workingDir . "/data/in/user",
        ];

        $fs->mkdir($structure);
    }
}
