<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use  Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Yaml\Yaml;

class Adapter
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $configClass = '';

    /**
     * @var string data format, 'yaml' or 'json'
     */
    protected $format = 'yaml';

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param $format
     * @return $this
     * @throws \Exception
     */
    public function setFormat($format)
    {
        if (!in_array($format, array('yaml', 'json'))) {
            throw new \Exception("Configuration format '{$format}' not supported");
        }
        $this->format = $format;
        return $this;
    }


    /**
     * @param array $config
     * @return $this
     */
    public function setConfig($config)
    {
        $className = $this->configClass;
        $this->config = (new $className())->parse(array("config" => $config));
        return $this;
    }

    /**
     *
     * Read configuration from file
     *
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function readFromFile($file)
    {
        $fs = new Filesystem();
        if (!$fs->exists($file)) {
            throw new \Exception("File '$file' not found.");
        }

        $serialized = $this->getContents($file);

        if ($this->getFormat() == 'yaml') {
            $yaml = new Yaml();
            $data = $yaml->parse($serialized);
        } elseif ($this->getFormat() == 'json') {
            $encoder = new JsonEncoder();
            $data = $encoder->decode($serialized, $encoder::FORMAT);
        }
        $this->setConfig($data);
        return $this->getConfig();
    }

    /**
     *
     * Write configuration to file in given format
     *
     * @param $file
     */
    public function writeToFile($file)
    {
        if ($this->getFormat() == 'yaml') {
            $yaml = new Yaml();
            $serialized = $yaml->dump($this->getConfig(), 10);
        } elseif ($this->getFormat() == 'json') {
            $encoder = new JsonEncoder();
            $serialized = $encoder->encode($this->getConfig(), $encoder::FORMAT);
        }
        $fs = new Filesystem();
        $fs->dumpFile($file, $serialized);
    }

    /**
     * @param $file
     * @return mixed
     * @throws \Exception
     */
    public function getContents($file)
    {
        if (!(new Filesystem())->exists($file)) {
            throw new \Exception("File" . $file . " not found.");
        }
        $fileHandler = new SplFileInfo($file, "", basename($file));
        if ($fileHandler) {
            return $fileHandler->getContents();
        } else {
            throw new \Exception("File" . $file . " not found.");
        }
    }

}
