<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\Syrup\Exception\ApplicationException;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\Process\Process;

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
     * Current temporary directory when running the container.
     *
     * @var string
     */
    private $currentTmpDir;

    /**
     * Pathname to currently used configuration file.
     *
     * @var string
     */
    private $currentConfigFile;


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
     * Initialize container environment.
     * @param Container $container Docker container.
     * @param array $config Configuration injected into docker image.
     */
    public function initialize(Container $container, array $config)
    {
        // create temporary working folder and all of its sub-folders
        $fs = new Filesystem();
        $this->currentTmpDir = $this->getTmpFolder();
        $fs->mkdir($this->currentTmpDir);
        $container->createDataDir($this->currentTmpDir);

        // download source files
        $reader = new Reader($this->getStorageApiClient());
        $reader->setFormat($container->getImage()->getConfigFormat());

        try {
            if (isset($config["storage"]["input"]["tables"]) && count($config["storage"]["input"]["tables"])) {
                $this->getLog()->debug("Downloading source tables.");
                $reader->downloadTables(
                    $config["storage"]["input"]["tables"],
                    $this->currentTmpDir . "/data/in/tables"
                );
            }
            if (isset($config["storage"]["input"]["files"]) && count($config["storage"]["input"]["files"])) {
                $this->getLog()->debug("Downloading source files.");
                $reader->downloadFiles(
                    $config["storage"]["input"]["files"],
                    $this->currentTmpDir . "/data/in/files"
                );
            }
        } catch (ClientException $e) {
            throw new UserException("Cannot import data from Storage API: " . $e->getMessage(), $e);
        }

        // create configuration file injected into docker
        $adapter = new Configuration\Container\Adapter($container->getImage()->getConfigFormat());
        $adapter->setConfig($config);
        $this->currentConfigFile = $this->currentTmpDir . "/data/config" . $adapter->getFileExtension();
        $adapter->writeToFile($this->currentConfigFile);
    }



    /**
     * @param Container $container
     * @param $id
     * @return \Symfony\Component\Process\Process
     * @throws \Exception
     */
    public function run(Container $container, $id)
    {

        // Check if container not running
        $process = new Process('sudo docker ps | grep ' . escapeshellarg($id) . ' | wc -l');
        $process->run();
        if (trim($process->getOutput()) !== '0') {
            throw new UserException("Container '{$id}' already running.");
        }

        // Check old containers, delete if found
        $process = new Process('sudo docker ps -a | grep ' . escapeshellarg($id) . ' | wc -l');
        $process->run();
        if (trim($process->getOutput()) !== '0') {
            (new Process('sudo docker rm ' . escapeshellarg($id)))->run();
        }

        // set environment variables
        $tokenInfo = $this->getStorageApiClient()->getLogData();
        $envs = [
            "KBC_RUNID" => $this->getStorageApiClient()->getRunId(),
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"],
            "KBC_PROJECTNAME" => $tokenInfo["owner"]["name"],
            "KBC_TOKENID" => $tokenInfo["id"],
            "KBC_TOKENDESC" => $tokenInfo["description"]
        ];
        if ($container->getImage()->getForwardToken()) {
            $envs["KBC_TOKEN"] = $this->getStorageApiClient()->getTokenString();
        }
        $container->setEnvironmentVariables($envs);

        // run the container
        $process = $container->run($id);
        if ($process->getOutput() && !$container->getImage()->isStreamingLogs()) {
            $this->getLog()->info($process->getOutput());
        } else {
            $this->getLog()->info("Docker container processing finished.");
        }
        return $process;
    }


    /**
     * Store results of last executed container (perform output mapping)
     * @param Container $container
     * @param $config
     */
    public function storeOutput(Container $container, $config)
    {
        $this->getLog()->debug("Storing results.");

        $writer = new Writer($this->getStorageApiClient());
        $writer->setFormat($container->getImage()->getConfigFormat());

        $outputTablesConfig = [];
        $outputFilesConfig = [];

        if (isset($config["storage"]["output"]["tables"]) && count($config["storage"]["output"]["tables"])) {
            $outputTablesConfig = $config["storage"]["output"]["tables"];
        }
        if (isset($config["storage"]["output"]["files"]) && count($config["storage"]["output"]["files"])) {
            $outputFilesConfig = $config["storage"]["output"]["files"];
        }

        try {
            $this->getLog()->debug("Uploading output tables and files.");
            $writer->uploadTables($this->currentTmpDir . "/data/out/tables", $outputTablesConfig);
            try {
                $writer->uploadFiles($this->currentTmpDir . "/data/out/files", $outputFilesConfig);
            } catch (ManifestMismatchException $e) {
                $this->getLog()->warn($e->getMessage());
            }
        } catch (ClientException $e) {
            throw new UserException("Cannot export data to Storage API: " . $e->getMessage(), $e);
        }

        if (isset($config["storage"]["input"]["files"])) {
            // tag input files
            $reader = new Reader($this->getStorageApiClient());
            $reader->tagFiles($config["storage"]["input"]["files"]);
        }

        $container->dropDataDir();
    }


    /**
     * Archive data directory and save it to Storage, do not actually run the container.
     * @param Container $container
     * @param array $tags Arbitrary storage tags
     */
    public function storeDataArchive(Container $container, array $tags)
    {
        $zip = new \ZipArchive();
        $zipFileName = 'data.zip';
        $zipDir = $this->currentTmpDir . DIRECTORY_SEPARATOR . 'zip';
        $fs = new Filesystem();
        $fs->mkdir($zipDir);
        $zip->open($zipDir. DIRECTORY_SEPARATOR . $zipFileName, \ZipArchive::CREATE);
        $finder = new Finder();
        /** @var SplFileInfo $item */
        foreach ($finder->in($this->currentTmpDir) as $item) {
            if ($item->getPathname() == $zipDir) {
                continue;
            }
            if ($item->isDir()) {
                if (!$zip->addEmptyDir($item->getRelativePathname())) {
                    throw new ApplicationException("Failed to add directory: ".$item->getFilename());
                }
            } else {
                if (!$zip->addFile($item->getPathname(), $item->getRelativePathname())) {
                    throw new ApplicationException("Failed to add file: ".$item->getFilename());
                }
            }
        }
        $zip->close();

        $writer = new Writer($this->getStorageApiClient());
        $writer->setFormat($container->getImage()->getConfigFormat());
        // zip archive must be created in special directory, because uploadFiles is recursive
        $writer->uploadFiles(
            $zipDir,
            [
                [
                    'source' => $zipFileName,
                    'tags' => $tags,
                    'is_permanent' => false,
                    'is_encrypted' => true,
                    'is_public' => false,
                    'notify' => false
                ]
            ]
        );
    }
}
