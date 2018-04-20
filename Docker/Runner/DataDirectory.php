<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Psr\Log\LoggerInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
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
        return "sudo docker run --rm --volume=" .
            $this->workingDir . DIRECTORY_SEPARATOR . "data:/data alpine sh -c 'chown {$uid} /data -R && "
                . "chmod -R u+wrX /data'";
    }

    public function normalizePermissions()
    {
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new ExponentialBackOffPolicy(10000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
        $proxy->call(function () use (&$process) {
            $command = $this->getNormalizeCommand();
            $process = new Process($command);
            $process->setTimeout(60);
            $process->run();
        });
    }

    public function getDataDir()
    {
        return $this->workingDir . "/data";
    }

    public function dropDataDir()
    {
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
            $this->workingDir . "/data/out",
            $this->workingDir . "/data/out/tables",
            $this->workingDir . "/data/out/files",
            $this->workingDir . "/data/in/user",
        ];

        $fs->mkdir($structure);
    }
}
