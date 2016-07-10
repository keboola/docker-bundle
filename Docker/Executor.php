<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\RequestException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
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
     * @var ObjectEncryptor
     */
    private $encryptor;

    /**
     * @var Image
     */
    private $mainImage;

    /**
     * @return string
     */
    public function getTmpFolder()
    {
        return $this->tmpFolder;
    }

    /**
     * @return Client
     */
    public function getStorageApiClient()
    {
        return $this->storageApiClient;
    }

    /**
     * @return Logger
     */
    public function getLog()
    {
        return $this->log;
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
     * @param Client $storageApi
     * @param LoggersService|Logger $log
     * @param Credentials $oauthCredentialsClient
     * @param ObjectEncryptor $encryptor
     * @param string $tmpFolder
     */
    public function __construct(
        Client $storageApi,
        LoggersService $log,
        Credentials $oauthCredentialsClient,
        ObjectEncryptor $encryptor,
        $tmpFolder
    ) {
        $this->storageApiClient = $storageApi;
        $this->log = $log->getLog();
        $this->containerLog = $log->getContainerLog();
        $this->oauthCredentialsClient = $oauthCredentialsClient;
        $this->logService = $log;
        $this->tmpFolder = $tmpFolder;
        $this->encryptor = $encryptor;
    }

    public function createDataDir()
    {
        $fs = new Filesystem();
        $this->currentTmpDir = $this->getTmpFolder();
        $fs->mkdir($this->currentTmpDir);

        $structure = [
            $this->currentTmpDir . "/data",
            $this->currentTmpDir . "/data/in",
            $this->currentTmpDir . "/data/in/tables",
            $this->currentTmpDir . "/data/in/files",
            $this->currentTmpDir . "/data/in/user",
            $this->currentTmpDir . "/data/out",
            $this->currentTmpDir . "/data/out/tables",
            $this->currentTmpDir . "/data/out/files"
        ];

        $fs->mkdir($structure);
    }

    public function dropDataDir()
    {
        $fs = new Filesystem();
        $structure = [
            $this->currentTmpDir . "/in/tables",
            $this->currentTmpDir . "/in/files",
            $this->currentTmpDir . "/in/user",
            $this->currentTmpDir . "/in",
            $this->currentTmpDir . "/out/files",
            $this->currentTmpDir . "/out/tables",
            $this->currentTmpDir . "/out",
            $this->currentTmpDir
        ];
        $finder = new Finder();
        $finder->files()->in($structure);
        $fs->remove($finder);
        $fs->remove($structure);
    }

    public function loadInputData()
    {
        // download source files
        $reader = new Reader($this->getStorageApiClient());
        $reader->setFormat($this->mainImage->getConfigFormat());

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

    public function createStateFile(array $state)
    {
        // Store state
        $stateAdapter = new Configuration\State\Adapter($this->mainImage->getConfigFormat());
        $stateAdapter->setConfig($state);
        $stateFile = $this->currentTmpDir . "/data/in/state" . $stateAdapter->getFileExtension();
        $stateAdapter->writeToFile($stateFile);
    }

    public function createConfigFile($sandboxed, $action)
    {
        // create configuration file injected into docker
        $adapter = new Configuration\Container\Adapter($this->mainImage->getConfigFormat());
        try {
            $configData = $this->configData;
            // remove runtime parameters which is not supposed to be passed into the container
            unset($configData['runtime']);
            // add image parameters which are supposed to be passed into the container

            if ($sandboxed) {
                // do not decrypt image parameters on sandboxed calls
                $configData['image_parameters'] = $this->mainImage->getImageParameters();
            } else {
                $configData['image_parameters'] = $this->encryptor->decrypt($this->mainImage->getImageParameters());
            }

            // read authorization
            if (isset($configData["authorization"]["oauth_api"]["id"])) {
                $credentials = $this->getOauthCredentialsClient()->getDetail(
                    $this->getComponentId(),
                    $configData["authorization"]["oauth_api"]["id"]
                );
                if ($sandboxed) {
                    $decrypted = $credentials;
                } else {
                    $decrypted = $this->encryptor->decrypt($credentials);
                }
                $configData["authorization"]["oauth_api"]["credentials"] = $decrypted;
            }

            // action
            $configData["action"] = $action;

            $fileName = $this->currentTmpDir . "/data/config" . $adapter->getFileExtension();
            $adapter->setConfig($configData);
            $adapter->writeToFile($fileName);
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Error in configuration: " . $e->getMessage(), $e);
        } catch (RequestException $e) {
            throw new UserException("Error loading credentials: " . $e->getMessage(), $e);
        }
    }

    public function runMainContainer()
    {

    }
    public function runProcessor()
    {

    }
    public function moveOutputToInput()
    {

    }

    public function prepareImage($imageConfiguration)
    {
        $image = Image::factory($this->encryptor, $this->log, $imageConfiguration);
        return $image;
    }

    public function prepareContainer(Image $image, $id)
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

        $container = new Container($image, $this->log, $this->containerLog, $this->currentTmpDir);
        return $container;
    }


    /**
     * Initialize container environment.
     * @param array $containerConfig Configuration injected into docker container.
     * @param array $state Component state.
     * @param array $imageConfig Definition of the main image.
     * @param bool $sandboxed True if the container runs sandboxed.
     * @param string $action Component action.
     */
    public function initialize(array $containerConfig, array $state, array $imageConfig, $sandboxed, $action = "run")
    {
        $this->configData = $containerConfig;
        $this->createDataDir();
        $this->mainImage = $this->prepareImage($imageConfig);
        $this->logService->setVerbosity($this->mainImage->getLoggerVerbosity());
        $this->createConfigFile($sandboxed, $action);
        $this->createStateFile($state);
        $this->loadInputData();
    }


    /**
     * @param string $id
     * @param array $tokenInfo Storage API token information as returned by verifyToken()
     * @param string $configId Configuration passed to the container (not used for any KBC work).
     * @param null $processOutput Output variable to catch process output
     */
    public function run($id, $tokenInfo, $configId, &$processOutput = null)
    {
        $container = $this->prepareContainer($this->mainImage, $id);
        // set environment variables
        $envs = [
            "KBC_RUNID" => $this->getStorageApiClient()->getRunId(),
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"],
            "KBC_DATADIR" => '/data/',
            "KBC_CONFIGID" => $configId,
        ];
        if ($this->mainImage->getForwardToken()) {
            $envs["KBC_TOKEN"] = $tokenInfo["token"];
        }
        if ($this->mainImage->getForwardTokenDetails()) {
            $envs["KBC_PROJECTNAME"] = $tokenInfo["owner"]["name"];
            $envs["KBC_TOKENID"] = $tokenInfo["id"];
            $envs["KBC_TOKENDESC"] = $tokenInfo["description"];
        }

        $container->setEnvironmentVariables($envs);

        // run the container
        $process = $container->run($id, $this->configData);
        $processOutput = trim($process->getOutput());
    }


    /**
     * @param array $previousState
     * @throws ClientException
     * @throws \Exception
     */
    public function storeOutput($previousState)
    {
        $this->getLog()->debug("Storing results.");

        $writer = new Writer($this->getStorageApiClient());
        $writer->setFormat($this->mainImage->getConfigFormat());

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
        if ($this->mainImage->isDefaultBucket()) {
            if (!$this->getConfigurationId()) {
                throw new UserException("Configuration ID not set, but is required for default_bucket option.");
            }
            $uploadTablesOptions["bucket"] =
                $this->mainImage->getDefaultBucketStage() . ".c-" .
                $this->getSanitizedComponentId() . "-" . $this->getConfigurationId();
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
            if ($this->mainImage->isDefaultBucket()) {
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
                if (!$previousState) {
                    $previousState = new \stdClass();
                }
                $writer->updateState(
                    $this->getComponentId(),
                    $this->getConfigurationId(),
                    $this->currentTmpDir . "/data/out/state",
                    $previousState
                );
            }
        }
        $this->dropDataDir();
    }


    /**
     * Archive data directory and save it to Storage, do not actually run the container.
     * @param array $tags Arbitrary storage tags
     */
    public function storeDataArchive(array $tags)
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
        $writer->setFormat($this->mainImage->getConfigFormat());
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
