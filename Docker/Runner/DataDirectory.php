<?php

namespace Keboola\DockerBundle\Docker\Runner;

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
     * DataDirectory constructor.
     * @param $workingDir
     */
    public function __construct($workingDir)
    {
        $this->workingDir = $workingDir;
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

    public function getDataDir()
    {
        return $this->workingDir . "/data";
    }

    public function dropDataDir()
    {
        // normalize user permissions
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $command = "sudo docker run --volume=" . $this->workingDir . DIRECTORY_SEPARATOR . "data:/data alpine sh -c 'chown {$uid} /data -R'";
        (new Process($command))->mustRun();

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
