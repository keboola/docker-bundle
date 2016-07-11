<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
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
     * @param ContainerFactory $containerFactory
     * @param Credentials $oauthCredentialsClient
     * @param ObjectEncryptor $encryptor
     * @param string $tmpFolder
     */
    public function __construct(
        Client $storageApi,
        LoggersService $log,
        ContainerFactory $containerFactory,
        Credentials $oauthCredentialsClient,
        ObjectEncryptor $encryptor,
        $tmpFolder
    ) {
        $this->storageApiClient = $storageApi;
        $this->log = $log->getLog();
        $this->containerLog = $log->getContainerLog();
        $this->oauthCredentialsClient = $oauthCredentialsClient;
        $this->logService = $log;
        $this->containerFactory = $containerFactory;
        $this->tmpFolder = $tmpFolder;
        $this->encryptor = $encryptor;
    }
 

 
}
