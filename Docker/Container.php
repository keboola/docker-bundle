<?php

namespace Keboola\DockerBundle\Docker;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
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
     * @return Process
     * @throws \Exception
     */
    public function run()
    {
        $id = $this->getImage()->prepare($this);
        $this->setId($id);

        if (!$this->getDataDir()) {
            throw new \Exception("Data directory not set.");
        }
        $process = new Process($this->getRunCommand());
        $process->run();
        if (!$process->isSuccessful()) {
            $message = $process->getErrorOutput();
            if (!$message) {
                $message = $process->getOutput();
            }
            if (!$message) {
                $message = "No error message.";
            }
            $data = array(
                "output" => $process->getOutput(),
                "errorOutput" => $process->getErrorOutput()
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
     * @return string
     */
    public function getRunCommand()
    {
        $envs = "";
        foreach($this->getEnvironmentVariables() as $key => $value) {
            $envs .=  " -e \"{$key}={$value}\"";
        }
        $command = "sudo docker run"
            . " --volume=" . escapeshellarg($this->getDataDir()). ":/data"
            . " --memory=" . escapeshellarg($this->getImage()->getMemory())
            . " --cpu-shares=" . escapeshellarg($this->getImage()->getCpuShares())
            . $envs
            . " --rm"
            // TODO --net + nastavení
            . " " . escapeshellarg($this->getId())
        ;
        return $command;
    }

}
