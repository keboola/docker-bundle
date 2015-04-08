<?php

namespace Keboola\DockerBundle\Docker;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Syrup\ComponentBundle\Exception\ApplicationException;
use Syrup\ComponentBundle\Exception\UserException;

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
     */
    public function __construct(Image $image)
    {
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
     * @param string $containerName suffix to the container tag
     * @return Process
     * @throws \Exception
     */
    public function run($containerName = "")
    {
        $id = $this->getImage()->prepare($this);
        $this->setId($id);

        if (!$this->getDataDir()) {
            throw new \Exception("Data directory not set.");
        }
        $process = new Process($this->getRunCommand($containerName));
        $process->setTimeout($this->getImage()->getProcessTimeout());
        $process->run();
        if (!$process->isSuccessful()) {
            $message = substr($process->getErrorOutput(), 0, 8192);
            if (!$message) {
                $message = substr($process->getOutput(), 0, 8192);
            }
            if (!$message) {
                $message = "No error message.";
            }
            $data = array(
                "output" => substr($process->getOutput(), 0, 8192),
                "errorOutput" => substr($process->getErrorOutput(), 0, 8192)
            );

            if ($process->getExitCode() == 1) {
                throw new UserException("Container '{$this->getId()}': {$message}", null, $data);
            } else {
                throw new ApplicationException("Container '{$this->getId()}': ({$process->getExitCode()}) {$message}", null, $data);
            }
        }
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
     * @param string $containerName
     * @return string
     */
    public function getRunCommand($containerName = "")
    {
        $envs = "";
        foreach ($this->getEnvironmentVariables() as $key => $value) {
            $envs .= " -e \"" . str_replace('"', '\"', $key) . "=" . str_replace('"', '\"', $key). "\"";
        }
        $command = "sudo docker run"
            . " --volume=" . escapeshellarg($this->getDataDir()). ":/data"
            . " --memory=" . escapeshellarg($this->getImage()->getMemory())
            . " --cpu-shares=" . escapeshellarg($this->getImage()->getCpuShares())
            . $envs
            . " --rm"
            . " --name=" . escapeshellarg(strtr($this->getId(), ":/", "--") . ($containerName ? "-" . $containerName : ""))
            // TODO --net + nastavení
            . " " . escapeshellarg($this->getId())
        ;
        return $command;
    }
}
