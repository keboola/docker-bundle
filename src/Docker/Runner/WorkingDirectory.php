<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Exception\ApplicationException;
use Psr\Log\LoggerInterface;
use Retry\BackOff\UniformRandomBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Filesystem\Filesystem;
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
            $this->workingDir . '/tmp',
            $this->workingDir . '/data',
            $this->workingDir . '/data/in',
            $this->workingDir . '/data/in/tables',
            $this->workingDir . '/data/in/files',
            $this->workingDir . '/data/in/user',
            $this->workingDir . '/data/out',
            $this->workingDir . '/data/out/tables',
            $this->workingDir . '/data/out/files',
        ];

        $fs->mkdir($structure);
    }

    public function getNormalizeCommand()
    {
        $uid = trim((Process::fromShellCommandline('id -u'))->mustRun()->getOutput());
        return "sudo chown {$uid} {$this->workingDir} -R && chmod -R u+wrX {$this->workingDir}";
    }

    public function normalizePermissions()
    {
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new UniformRandomBackOffPolicy(60000, 180000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
        try {
            $proxy->call(
                function () use (&$process) {
                    $this->logger->notice('Normalizing working directory permissions');
                    $command = $this->getNormalizeCommand();
                    $process = Process::fromShellCommandline($command);
                    $process->setTimeout(120);
                    $process->mustRun();
                },
            );
        } catch (ProcessTimedOutException) {
            throw new ApplicationException(
                'Could not normalize permissions.',
            );
        }
    }

    public function getDataDir()
    {
        return $this->workingDir . '/data';
    }

    public function getTmpDir()
    {
        return $this->workingDir . '/tmp';
    }

    public function dropWorkingDir(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->workingDir . DIRECTORY_SEPARATOR . 'data');
        $fs->remove($this->workingDir . DIRECTORY_SEPARATOR . 'tmp');
    }

    public function moveOutputToInput(): void
    {
        $fs = new Filesystem();

        // delete input
        $fs->remove([
            $this->workingDir . '/data/in',
            $this->workingDir . '/tmp',
        ]);

        // delete state file
        $fs->remove($this->workingDir . '/data/out/state.json');
        $fs->remove($this->workingDir . '/data/out/state.yml');

        // rename
        $fs->rename(
            $this->workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'out',
            $this->workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'in',
        );

        // create empty output
        $fs->mkdir([
            $this->workingDir . '/tmp',
            $this->workingDir . '/data/out/tables',
            $this->workingDir . '/data/out/files',
            $this->workingDir . '/data/in/user',
        ]);
    }
}
