<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader;
use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class Runner
{
    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var ObjectEncryptor
     */
    private $encryptor;

    /**
     * @var Client
     */
    private $storageClient;

    /**
     * @var Credentials
     */
    private $oauthClient;

    /**
     * @var LoggersService
     */
    private $loggerService;

    /**
     * @var DataDirectory
     */
    private $dataDirectory;

    /**
     * @var StateFile
     */
    private $stateFile;

    /**
     * @var ConfigFile
     */
    private $configFile;

    /**
     * @var DataLoader
     */
    private $dataLoader;

    /**
     * @var ImageCreator
     */
    private $imageCreator;

    /**
     * @var string
     */
    private $commandToGetHostIp;

    /**
     * Runner constructor.
     * @param Temp $temp
     * @param ObjectEncryptor $encryptor
     * @param StorageApiService $storageApi
     * @param LoggersService $loggersService
     * @param string $oauthApiUrl
     * @param string $commandToGetHostIp
     * @param int $minLogPort
     * @param int $maxLogPort
     */
    public function __construct(
        Temp $temp,
        ObjectEncryptor $encryptor,
        StorageApiService $storageApi,
        LoggersService $loggersService,
        $oauthApiUrl,
        $commandToGetHostIp = 'ip -4 addr show docker0 | grep -Po \'inet \K[\d.]+\'',
        $minLogPort = 12202,
        $maxLogPort = 13202
    ) {
        /* the above port range is rather arbitrary, it intentionally excludes the default port (12201)
        to avoid mis-configured clients. */
        $this->temp = $temp;
        $this->encryptor = $encryptor;
        $this->storageClient = $storageApi->getClient();
        $this->oauthClient = new Credentials($this->storageClient->getTokenString(), [
            'url' => $oauthApiUrl
        ]);
        $this->oauthClient->enableReturnArrays(true);
        $this->loggerService = $loggersService;
        $this->commandToGetHostIp = $commandToGetHostIp;
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
    }

    private function createContainerFromImage(Image $image, $containerId, $environmentVariables)
    {
        return new Container(
            $containerId,
            $image,
            $this->loggerService->getLog(),
            $this->loggerService->getContainerLog(),
            $this->dataDirectory->getDataDir(),
            $environmentVariables,
            $this->commandToGetHostIp,
            $this->minLogPort,
            $this->maxLogPort
        );
    }

    private function shouldStoreState($componentId, $configurationId)
    {
        $storeState = false;
        if ($componentId && $configurationId) {
            $storeState = true;

            // Do not store state if configuration does not exist
            $components = new Components($this->storageClient);
            try {
                $components->getConfiguration($componentId, $configurationId);
            } catch (ClientException $e) {
                if ($e->getStringCode() == 'notFound' && $e->getPrevious()->getCode() == 404) {
                    $storeState = false;
                } else {
                    throw $e;
                }
            }
        }
        return $storeState;
    }

    /**
     * @param array $componentData
     * @param $configId
     * @param array $configData
     * @param array $state
     * @param string $action
     * @param string $mode
     * @param string $jobId
     * @return string
     */
    public function run(array $componentData, $configId, array $configData, array $state, $action, $mode, $jobId)
    {
        $component = new Component($componentData);
        $this->loggerService->getLog()->info("Running Component " . $component->getId(), $configData);
        $this->loggerService->setComponentId($component->getId());

        $sandboxed = $mode != 'run';
        try {
            $configData = (new Configuration\Container())->parse(['container' => $configData]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        $configData['storage'] = empty($configData['storage']) ? [] : $configData['storage'];
        $configData['processors'] = empty($configData['processors']) ? [] : $configData['processors'];
        $configData['parameters'] = empty($configData['parameters']) ? [] : $configData['parameters'];

        $this->dataDirectory = new DataDirectory($this->temp->getTmpFolder(), $this->loggerService->getLog());
        $this->stateFile = new StateFile(
            $this->dataDirectory->getDataDir(),
            $this->storageClient,
            $state,
            $component->getId(),
            $configId,
            $component->getConfigurationFormat()
        );
        $authorization = new Authorization($this->oauthClient, $this->encryptor, $component->getId(), $sandboxed);

        if ($sandboxed) {
            // do not decrypt image parameters on sandboxed calls
            $imageParameters = $component->getImageParameters();
        } else {
            $imageParameters = $this->encryptor->decrypt($component->getImageParameters());
        }
        $this->configFile = new ConfigFile(
            $this->dataDirectory->getDataDir(),
            $imageParameters,
            $authorization,
            $action,
            $component->getConfigurationFormat()
        );

        if ($component->hasDefaultBucket()) {
            if (!$configId) {
                throw new UserException("Configuration ID not set, but is required for default_bucket option.");
            }
            $defaultBucketName = $component->getDefaultBucketName($configId);
        } else {
            $defaultBucketName = '';
        }

        $this->dataLoader = new DataLoader(
            $this->storageClient,
            $this->loggerService->getLog(),
            $this->dataDirectory->getDataDir(),
            $configData['storage'],
            $defaultBucketName,
            $component->getConfigurationFormat(),
            $component->getStagingStorage()
        );
        $this->imageCreator = new ImageCreator(
            $this->encryptor,
            $this->loggerService->getLog(),
            $this->storageClient,
            $component,
            $configData
        );

        switch ($mode) {
            case 'run':
                $componentOutput = $this->runComponent($jobId, $configId, $component);
                break;
            case 'sandbox':
            case 'input':
            case 'dry-run':
                $componentOutput = $this->sandboxComponent($jobId, $configData, $mode, $configId, $component);
                break;
            default:
                throw new ApplicationException("Invalid run mode " . $mode);
        }
        return $componentOutput;
    }

    public function runComponent($jobId, $configId, Component $component)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        $componentOutput = $this->runImages($jobId, $configId, $component);

        // finalize
        $this->dataLoader->storeOutput();
        if ($this->shouldStoreState($component->getId(), $configId)) {
            $this->stateFile->storeStateFile();
        }
        $this->dataDirectory->dropDataDir();
        $this->loggerService->getLog()->info("Docker Component " . $component->getId() . " finished.");
        return $componentOutput;
    }

    public function sandboxComponent($jobId, $configData, $mode, $configId, Component $component)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        $componentOutput = '';
        if ($mode == 'dry-run') {
            $componentOutput = $this->runImages($jobId, $configId, $component);
        } else {
            $this->configFile->createConfigFile($configData);
        }

        $this->dataLoader->storeDataArchive([$mode, 'docker', $component->getId()]);
        // finalize
        $this->dataDirectory->dropDataDir();
        return $componentOutput;
    }

    private function runImages($jobId, $configId, Component $component)
    {
        $componentOutput = '';
        $images = $this->imageCreator->prepareImages();
        $this->loggerService->setVerbosity($component->getLoggerVerbosity());
        $tokenInfo = $this->storageClient->verifyToken();

        $counter = 0;
        foreach ($images as $priority => $image) {
            $environment = new Environment(
                $configId,
                $image->getSourceComponent(),
                $image->getConfigData()['parameters'],
                $tokenInfo,
                $this->storageClient->getRunId(),
                $this->storageClient->getApiUrl()
            );

            $this->configFile->createConfigFile($image->getConfigData());
            $containerId = $jobId . '-' . ($this->storageClient->getRunId() ?: 'norunid');
            $container = $this->createContainerFromImage(
                $image,
                $containerId,
                $environment->getEnvironmentVariables()
            );
            $output = $container->run();
            if ($image->isMain()) {
                $componentOutput = $output->getOutput();
            }
            $counter++;
            if ($counter < count($images)) {
                $this->dataDirectory->moveOutputToInput();
            }
        }
        return $componentOutput;
    }
}
