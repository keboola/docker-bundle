<?php

namespace Keboola\DockerBundle\Docker\Configuration;

use Symfony\Component\Config\Definition\Processor;
use  Keboola\DockerBundle\Docker\Configuration;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
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
        $processor = new Processor();
        $className = $this->configClass;
        $configurationDefinition = new $className();
        $this->config = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        return $this;
    }

    /**
     *
     * Read configuration from file
     *
     * @param $file
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
     * @return string
     */
    public function getContents($file)
        {
            $level = error_reporting(0);
            $content = file_get_contents($file);
            error_reporting($level);
            if (false === $content) {
                $error = error_get_last();
                throw new \RuntimeException($error['message']);
            }

            return $content;
        }

}
