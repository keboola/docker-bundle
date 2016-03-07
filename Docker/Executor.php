<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
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
     * @var Credentials
     */
    protected $oauthCredentialsClient;

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
     *
     */
    public function getSanitizedComponentId()
    {
        return preg_replace('/[^a-zA-Z0-9-]/i', '-', $this->componentId);
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
     * @return Credentials
     */
    public function getOauthCredentialsClient()
    {
        return $this->oauthCredentialsClient;
    }

    /**
     * @param Credentials $oauthCredentialsClient
     * @return $this
     */
    public function setOauthCredentialsClient($oauthCredentialsClient)
    {
        $this->oauthCredentialsClient = $oauthCredentialsClient;

        return $this;
    }

    /**
     * @param Client $storageApi
     * @param Logger $log
     * @param Credentials $oauthCredentialsClient
     * @param $tmpFolder
     */
    public function __construct(Client $storageApi, Logger $log, Credentials $oauthCredentialsClient, $tmpFolder)
    {
        $this->setStorageApiClient($storageApi);
        $this->setLog($log);
        $this->setOauthCredentialsClient($oauthCredentialsClient);
        $this->setTmpFolder($tmpFolder);

    }


    /**
     * Initialize container environment.
     * @param Container $container Docker container.
     * @param array $config Configuration injected into docker image.
     * @param array $state Configuration state
     * @param bool $sandboxed
     */
    public function initialize(Container $container, array $config, array $state, $sandboxed)
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
            // remove runtime parameters which is not supposed to be passed into the container
            unset($configData['runtime']);
            // add image parameters which are supposed to be passed into the container

            if ($sandboxed) {
                // do not decrypt image parameters on sandboxed calls
                $configData['image_parameters'] = $container->getImage()->getImageParameters();
            } else {
                $configData['image_parameters'] = $container->getImage()->getEncryptor()->decrypt(
                    $container->getImage()->getImageParameters()
                );
            }

            // read authorization
            if (isset($configData["authorization"]["oauth_api"]["id"])) {
                $credentials = $this->getOauthCredentialsClient()->getDetail($this->getComponentId(), $configData["authorization"]["oauth_api"]["id"]);
                if ($sandboxed) {
                    $decrypted = $credentials;
                } else {
                    $decrypted = $container->getImage()->getEncryptor()->decrypt($credentials);
                }
                $configData["authorization"]["oauth_api"]["credentials"] = $decrypted;
            }
            $adapter->setConfig($configData);
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Error in configuration: " . $e->getMessage(), $e);
        } catch (\Keboola\OAuthV2Api\Exception\RequestException $e) {
            throw new UserException("Error loading credentials: " . $e->getMessage(), $e);
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
     * @param string $id
     * @param array $tokenInfo Storage API token information as returned by verifyToken()
     * @param string $configId Configuration passed to the container (not used for any KBC work).
     * @return string Container result message.
     */
    public function run(Container $container, $id, $tokenInfo, $configId)
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
        $envs = [
            "KBC_RUNID" => $this->getStorageApiClient()->getRunId(),
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"],
            "KBC_DATADIR" => '/data/',
            "KBC_CONFIGID" => $configId,
        ];
        if ($container->getImage()->getForwardToken()) {
            $envs["KBC_TOKEN"] = $tokenInfo["token"];
        }
        if ($container->getImage()->getForwardTokenDetails()) {
            $envs["KBC_PROJECTNAME"] = $tokenInfo["owner"]["name"];
            $envs["KBC_TOKENID"] = $tokenInfo["id"];
            $envs["KBC_TOKENDESC"] = $tokenInfo["description"];
        }

        $container->setEnvironmentVariables($envs);

        // run the container
        $process = $container->run($id, $this->configData);
        $message = $process->getOutput();
        if ($message && !$container->getImage()->isStreamingLogs()) {
            // trim the result if it is too long
            if (mb_strlen($message) > 64000) {
                $message = mb_substr($message, 0, 32000) . " ... " . mb_substr($message, -32000);
            }
        } else {
            $message = "Docker container processing finished.";
        }

        return $message;
    }


    /**
     * @param Container $container
     * @param mixed $state
     * @throws ClientException
     * @throws \Exception
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

        $uploadTablesOptions = ["mapping" => $outputTablesConfig];

        // Get default bucket
        if ($container->getImage()->isDefaultBucket()) {
            if (!$this->getConfigurationId()) {
                throw new UserException("Configuration ID not set, but is required for default_bucket option.");
            }
            $uploadTablesOptions["bucket"] = $container->getImage()->getDefaultBucketStage() . ".c-" . $this->getSanitizedComponentId() . "-" . $this->getConfigurationId();
            $this->getLog()->debug("Default bucket " . $uploadTablesOptions["bucket"]);
        }

        $writer->uploadTables($this->currentTmpDir . "/data/out/tables", $uploadTablesOptions);
        try {
            $writer->uploadFiles($this->currentTmpDir . "/data/out/files", ["mapping" => $outputFilesConfig]);
        } catch (ManifestMismatchException $e) {
            $this->getLog()->warn($e->getMessage());
        }

        if (isset($this->configData["storage"]["input"]["files"])) {
            // tag input files
            $writer->tagFiles($this->configData["storage"]["input"]["files"]);
        }

        if ($this->getComponentId() && $this->getConfigurationId()) {
            $storeState = true;

            // Do not store state if `default_bucket` and configurationId not really exists
            if ($container->getImage()->isDefaultBucket()) {
                $components = new Components($this->getStorageApiClient());
                try {
                    $components->getConfiguration($this->getComponentId(), $this->getConfigurationId());
                } catch (ClientException $e) {
                    if ($e->getStringCode() == 'notFound' && $e->getPrevious()->getCode() == 404) {
                        $storeState = false;
                    } else {
                        throw $e;
                    }
                }
            }

            // Store state
            if ($storeState) {
                if (!$state) {
                    $state = (object)array();
                }
                $writer->updateState(
                    $this->getComponentId(),
                    $this->getConfigurationId(),
                    $this->currentTmpDir . "/data/out/state",
                    $state
                );
            }
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
            ["mapping" =>
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
            ]
        );
    }
}
