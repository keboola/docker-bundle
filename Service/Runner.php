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
     * @var Environment
     */
    private $environment;

    /**
     * Runner constructor.
     * @param Temp $temp
     * @param ObjectEncryptor $encryptor
     * @param StorageApiService $storageApi
     * @param LoggersService $loggersService
     */
    public function __construct(
        Temp $temp,
        ObjectEncryptor $encryptor,
        StorageApiService $storageApi,
        LoggersService $loggersService
    ) {
        $this->temp = $temp;
        $this->encryptor = $encryptor;
        $this->storageClient = $storageApi->getClient();
        $this->oauthClient = new Credentials($this->storageClient->getTokenString());
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
            $configFormat
        );
        $this->environment = new Environment(
            $this->storageClient,
            $configId,
            $component['forward_token'],
            $component['forward_token_details']
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
                $componentOutput = $this->runComponent($jobId, $componentId, $configId);
                break;
            case 'sandbox':
            case 'input':
            case 'dry-run':
                $componentOutput = $this->sandboxComponent($jobId, $componentId, $configData, $mode);
                break;
            default:
                throw new ApplicationException("Invalid run mode " . $mode);
        }
        return $componentOutput;
    }

    public function runComponent($jobId, $componentId, $configId)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        $componentOutput = $this->runImages($jobId);

        // finalize
        $this->dataLoader->storeOutput();
        if ($this->shouldStoreState($componentId, $configId)) {
            $this->stateFile->storeStateFile();
        }
        $this->dataDirectory->dropDataDir();
        $this->loggerService->getLog()->info("Docker container '$componentId' finished.");
        return $componentOutput;
    }

    public function sandboxComponent($jobId, $componentId, $configData, $mode)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        $componentOutput = '';
        if ($mode == 'dry-run') {
            $componentOutput = $this->runImages($jobId);
        } else {
            $this->configFile->createConfigFile($configData);
        }

        $this->dataLoader->storeDataArchive([$mode, 'docker', $componentId]);
        // finalize
        $this->dataDirectory->dropDataDir();
        return $componentOutput;
    }

    private function runImages($jobId)
    {
        $componentOutput = '';
        $images = $this->imageCreator->prepareImages();
        $this->loggerService->setVerbosity($images[0]->getLoggerVerbosity());

        $counter = 0;
        foreach ($images as $priority => $image) {
            $this->configFile->createConfigFile($image->getConfigData());
            $containerId = $jobId . '-' . $this->storageClient->getRunId();
            if ($image->isMain()) {
                $environmentParameters = [];
            } else {
                $environmentParameters = $image->getConfigData()['parameters'];
            }
            $container = $this->createContainerFromImage(
                $image,
                $containerId,
                $this->environment->getEnvironmentVariables($environmentParameters)
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
