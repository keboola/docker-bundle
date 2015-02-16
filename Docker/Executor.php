<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Syrup\ComponentBundle\Exception\UserException;

class Executor
{
    /**
     * @var string
     */
    protected $tmpFolder = "/tmp";

    /**
     * @var Client
     */
    protected $storageApiClient;

    /**
     * @var Logger
     */
    protected $log;

    /**
     * @return string
     */
    public function getTmpFolder()
    {
        return $this->tmpFolder;
    }

    /**
     * @param string $tmpFolder
     * @return $this
     */
    public function setTmpFolder($tmpFolder)
    {
        $this->tmpFolder = $tmpFolder;
        return $this;
    }

    /**
     * @return Client
     */
    public function getStorageApiClient()
    {
        return $this->storageApiClient;
    }

    /**
     * @param Client $storageApiClient
     * @return $this
     */
    public function setStorageApiClient($storageApiClient)
    {
        $this->storageApiClient = $storageApiClient;
        return $this;
    }

    /**
     * @return Logger
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param Logger $log
     * @return $this
     */
    public function setLog($log)
    {
        $this->log = $log;
        return $this;
    }

    /**
     * @param Client $storageApi
     * @param Logger $log
     */
    public function __construct(Client $storageApi, Logger $log)
    {
        $this->setStorageApiClient($storageApi);
        $this->setLog($log);
    }

    /**
     * @param Container $container
     * @param $config
     * @return \Symfony\Component\Process\Process
     * @throws \Exception
     */
    public function run(Container $container, $config)
    {
        $fs = new Filesystem();
        $tmpDir = $this->getTmpFolder();
        $fs->mkdir($tmpDir);

        $container->createDataDir($tmpDir);

        $tokenInfo = $this->getStorageApiClient()->getLogData();
        $envs = array(
            "KBC_RUNID" => $this->getStorageApiClient()->getRunId(),
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"],
            "KBC_PROJECTNAME" => $tokenInfo["owner"]["name"],
            "KBC_TOKENID" => $tokenInfo["id"],
            "KBC_TOKENDESC" => $tokenInfo["description"],
        );
        $container->setEnvironmentVariables($envs);

        $reader = new Reader($this->getStorageApiClient());
        $reader->setFormat($container->getImage()->getConfigFormat());

        try {
            if (isset($config["storage"]["input"]["tables"]) && count($config["storage"]["input"]["tables"])) {
                $reader->downloadTables($config["storage"]["input"]["tables"], $tmpDir . "/data/in/tables");
            }
            if (isset($config["storage"]["input"]["files"]) && count($config["storage"]["input"]["files"])) {
                $reader->downloadFiles($config["storage"]["input"]["tables"], $tmpDir . "/data/in/files");
            }
        } catch (ClientException $e) {
            throw new UserException("Cannot import data from Storage API: " . $e->getMessage(), $e);
        }

        $adapter = new Configuration\Container\Adapter();
        $adapter->setFormat($container->getImage()->getConfigFormat());
        $adapter->setConfig($config);
        switch($adapter->getFormat()) {
            case 'yaml':
                $fileSuffix = ".yml";
                break;
            case 'json':
                $fileSuffix = ".json";
                break;
        }
        $adapter->writeToFile($tmpDir . "/data/config" . $fileSuffix);

        try {
            $process = $container->run($this->getStorageApiClient()->getRunId());
        } catch (ProcessTimedOutException $e) {
            throw new UserException("Running container exceeded the timeout of {$container->getImage()->getProcessTimeout()} seconds.");
        }

        if ($process->getOutput()) {
            $this->getLog()->info($process->getOutput());
        } else {
            $this->getLog()->info("Processing finished.");
        }

        $writer = new Writer($this->getStorageApiClient());
        $writer->setFormat($container->getImage()->getConfigFormat());

        $outputTablesConfig = array();
        $outputFilesConfig = array();

        if (isset($config["storage"]["output"]["tables"]) && count($config["storage"]["output"]["tables"])) {
            $outputTablesConfig = $config["storage"]["output"]["tables"];
        }
        if (isset($config["storage"]["output"]["files"]) && count($config["storage"]["output"]["files"])) {
            $outputFilesConfig = $config["storage"]["output"]["files"];
        }

        try {
            $writer->uploadTables($tmpDir . "/data/out/tables", $outputTablesConfig);
            $writer->uploadFiles($tmpDir . "/data/out/files", $outputFilesConfig);
        } catch (ClientException $e) {
            throw new UserException("Cannot export data to Storage API: " . $e->getMessage(), $e);
        }

        $container->dropDataDir();
        return $process;
    }
}
