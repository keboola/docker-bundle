<?php

namespace Keboola\DockerBundle\Service;

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
     * Runner constructor.
     * @param Temp $temp
     * @param ObjectEncryptor $encryptor
     * @param StorageApiService $storageApi
     * @param LoggersService $loggersService
     * @param string $oauthApiUrl
     */
    public function __construct(
        Temp $temp,
        ObjectEncryptor $encryptor,
        StorageApiService $storageApi,
        LoggersService $loggersService,
        $oauthApiUrl
    ) {
        $this->temp = $temp;
        $this->encryptor = $encryptor;
        $this->storageClient = $storageApi->getClient();
        $this->oauthClient = new Credentials($this->storageClient->getTokenString(), [
            'url' => $oauthApiUrl
        ]);
        $this->oauthClient->enableReturnArrays(true);
        $this->loggerService = $loggersService;
    }

    private function getSanitizedComponentId($componentId)
    {
        return preg_replace('/[^a-zA-Z0-9-]/i', '-', $componentId);
    }

    private function createContainerFromImage(Image $image, $containerId, $environmentVariables)
    {
        return new Container(
            $containerId,
            $image,
            $this->loggerService->getLog(),
            $this->loggerService->getContainerLog(),
            $this->dataDirectory->getDataDir(),
            $environmentVariables
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
     * @param array $component
     * @param $configId
     * @param array $configData
     * @param array $state
     * @param string $action
     * @param string $mode
     * @param string $jobId
     * @return string
     */
    public function run(array $component, $configId, array $configData, array $state, $action, $mode, $jobId)
    {
        $component['id'] = empty($component['id']) ? '' : $component['id'];
        $component['data'] = empty($component['data']) ? [] : $component['data'];
        $this->loggerService->getLog()->info("Running Docker container '{$component['id']}'.", $configData);
        $componentId = $component['id'];
        $component = (new Configuration\Component())->parse(['config' => $component['data']]);
        $component['image_parameters'] = empty($component['image_parameters']) ? [] : $component['image_parameters'];
        $this->loggerService->setComponentId($componentId);

        $sandboxed = $mode != 'run';
        try {
            $configData = (new Configuration\Container())->parse(['container' => $configData]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        $configData['storage'] = empty($configData['storage']) ? [] : $configData['storage'];
        $configData['processors'] = empty($configData['processors']) ? [] : $configData['processors'];
        $configFormat = $component['configuration_format'];

        $this->dataDirectory = new DataDirectory($this->temp->getTmpFolder());
        $this->stateFile = new StateFile(
            $this->dataDirectory->getDataDir(),
            $this->storageClient,
            $state,
            $componentId,
            $configId,
            $configFormat
        );
        $authorization = new Authorization($this->oauthClient, $this->encryptor, $componentId, $sandboxed);

        if ($sandboxed) {
            // do not decrypt image parameters on sandboxed calls
            $imageParameters = $component['image_parameters'];
        } else {
            $imageParameters = $this->encryptor->decrypt($component['image_parameters']);
        }
        $this->configFile = new ConfigFile(
            $this->dataDirectory->getDataDir(),
            $imageParameters,
            $authorization,
            $action,
            $configFormat
        );

        if (!empty($component['default_bucket'])) {
            if (!$configId) {
                throw new UserException("Configuration ID not set, but is required for default_bucket option.");
            }
            $defaultBucketName = $component['default_bucket_stage'] . ".c-" .
                $this->getSanitizedComponentId($componentId) . "-" . $configId;
        } else {
            $defaultBucketName = '';
        }

        $this->dataLoader = new DataLoader(
            $this->storageClient,
            $this->loggerService->getLog(),
            $this->dataDirectory->getDataDir(),
            $configData['storage'],
            $defaultBucketName,
            $configFormat,
            $component['staging_storage']
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

    public function runComponent($jobId, $configId, $component)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        $componentOutput = $this->runImages($jobId, $configId, $component);

        // finalize
        $this->dataLoader->storeOutput();
        if ($this->shouldStoreState($component['id'], $configId)) {
            $this->stateFile->storeStateFile();
        }
        $this->dataDirectory->dropDataDir();
        $this->loggerService->getLog()->info("Docker container '" . $component['id'] . "' finished.");
        return $componentOutput;
    }

    public function sandboxComponent($jobId, $configData, $mode, $configId, $component)
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

        $this->dataLoader->storeDataArchive([$mode, 'docker', $component['id']]);
        // finalize
        $this->dataDirectory->dropDataDir();
        return $componentOutput;
    }

    private function runImages($jobId, $configId, $component)
    {
        $componentOutput = '';
        $images = $this->imageCreator->prepareImages();
        $this->loggerService->setVerbosity($images[0]->getLoggerVerbosity());
        $tokenInfo = $this->storageClient->verifyToken();

        $counter = 0;
        foreach ($images as $priority => $image) {
            $environment = new Environment(
                $configId,
                $component,
                $image->getConfigData()['parameters'],
                $tokenInfo,
                $this->storageClient->getRunId(),
                $this->storageClient->getApiUrl()
            );

            $this->configFile->createConfigFile($image->getConfigData());
            $containerId = $jobId . '-' . $this->storageClient->getRunId();
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
