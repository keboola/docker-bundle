<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\Syrup\Exception\ApplicationException;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
     * Component configuration which will be passed to the container.
     *
     * @var array
     */
    private $configData;

    /**
     * @var
     */
    protected $componentId;

    /**
     * @var
     */
    protected $configurationId;

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
     * @return mixed
     */
    public function getComponentId()
    {
        return $this->componentId;
    }

    /**
     * @param mixed $componentId
     * @return $this
     */
    public function setComponentId($componentId)
    {
        $this->componentId = $componentId;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getConfigurationId()
    {
        return $this->configurationId;
    }

    /**
     * @param mixed $configurationId
     * @return $this
     */
    public function setConfigurationId($configurationId)
    {
        $this->configurationId = $configurationId;

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
     * @param array $state Configuration state
     */
    public function initialize(Container $container, array $config, array $state = null)
    {
        $this->configData = $config;
        // create temporary working folder and all of its sub-folders
        $fs = new Filesystem();
        $this->currentTmpDir = $this->getTmpFolder();
        $fs->mkdir($this->currentTmpDir);
        $container->createDataDir($this->currentTmpDir);

        // create configuration file injected into docker
        $adapter = new Configuration\Container\Adapter($container->getImage()->getConfigFormat());
        try {
            $configData = $this->configData;
            unset($configData['volatileParameters']);
            $adapter->setConfig($configData);
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Error in configuration: " . $e->getMessage(), $e);
        }
        $this->currentConfigFile = $this->currentTmpDir . "/data/config" . $adapter->getFileExtension();
        $adapter->writeToFile($this->currentConfigFile);

        // Store state
        $stateAdapter = new Configuration\State\Adapter($container->getImage()->getConfigFormat());
        $stateAdapter->setConfig($state);
        $stateFile = $this->currentTmpDir . "/data/in/state" . $stateAdapter->getFileExtension();
        $stateAdapter->writeToFile($stateFile);

        // download source files
        $reader = new Reader($this->getStorageApiClient());
        $reader->setFormat($container->getImage()->getConfigFormat());

        try {
            if (isset($this->configData["storage"]["input"]["tables"]) &&
                count($this->configData["storage"]["input"]["tables"])
            ) {
                $this->getLog()->debug("Downloading source tables.");
                $reader->downloadTables(
                    $this->configData["storage"]["input"]["tables"],
                    $this->currentTmpDir . "/data/in/tables"
                );
            }
            if (isset($this->configData["storage"]["input"]["files"]) &&
                count($this->configData["storage"]["input"]["files"])
            ) {
                $this->getLog()->debug("Downloading source files.");
                $reader->downloadFiles(
                    $this->configData["storage"]["input"]["files"],
                    $this->currentTmpDir . "/data/in/files"
                );
            }
        } catch (ClientException $e) {
            throw new UserException("Cannot import data from Storage API: " . $e->getMessage(), $e);
        }
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
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"]
        ];
        if ($container->getImage()->getForwardToken()) {
            $envs["KBC_TOKEN"] = $this->getStorageApiClient()->getTokenString();
        }
        if ($container->getImage()->getForwardTokenDetails()) {
            $envs["KBC_PROJECTNAME"] = $tokenInfo["owner"]["name"];
            $envs["KBC_TOKENID"] = $tokenInfo["id"];
            $envs["KBC_TOKENDESC"] = $tokenInfo["description"];
        }

        $container->setEnvironmentVariables($envs);

        // run the container
        $process = $container->run($id, $this->configData);
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
     * @param mixed $state
     */
    public function storeOutput(Container $container, $state = null)
    {
        $this->getLog()->debug("Storing results.");

        $writer = new Writer($this->getStorageApiClient());
        $writer->setFormat($container->getImage()->getConfigFormat());

        $outputTablesConfig = [];
        $outputFilesConfig = [];

        if (isset($this->configData["storage"]["output"]["tables"]) &&
            count($this->configData["storage"]["output"]["tables"])
        ) {
            $outputTablesConfig = $this->configData["storage"]["output"]["tables"];
        }
        if (isset($this->configData["storage"]["output"]["files"]) &&
            count($this->configData["storage"]["output"]["files"])
        ) {
            $outputFilesConfig = $this->configData["storage"]["output"]["files"];
        }

        $this->getLog()->debug("Uploading output tables and files.");
        $writer->uploadTables($this->currentTmpDir . "/data/out/tables", $outputTablesConfig);
        try {
            $writer->uploadFiles($this->currentTmpDir . "/data/out/files", $outputFilesConfig);
        } catch (ManifestMismatchException $e) {
            $this->getLog()->warn($e->getMessage());
        }

        if (isset($this->configData["storage"]["input"]["files"])) {
            // tag input files
            $writer->tagFiles($this->configData["storage"]["input"]["files"]);
        }

        if ($this->getComponentId() && $this->getConfigurationId()) {
            // Store state
            if (!$state) {
                $state = (object) array();
            }
            $writer->updateState(
                $this->getComponentId(),
                $this->getConfigurationId(),
                $this->currentTmpDir . "/data/out/state",
                $state
            );
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
