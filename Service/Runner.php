<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Configuration\State;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ComponentParameters;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Docker\Runner\ContainerCreator;
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
     * @var ContainerCreator
     */
    private $containerCreator;

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

    public function getSanitizedComponentId($componentId)
    {
        return preg_replace('/[^a-zA-Z0-9-]/i', '-', $componentId);
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
     * @param $action
     * @param $mode
     * @return string
     */
    public function run(array $component, $configId, array $configData, array $state, $action, $mode)
    {
        $this->loggerService->getLog()->info("Running Docker container '{$component['id']}'.", $configData);
        $sandboxed = $mode != 'run';
        $componentId = $component['id'];
        $component = (new Configuration\Component())->parse(['config' => $component['data']]);
        $component['image_parameters'] = empty($component['image_parameters']) ? [] : $component['image_parameters'];
        $this->loggerService->setComponentId($componentId);
        $configData = (new Configuration\Container())->parse(['container' => $configData]);
        $configData['storage'] = empty($configData['storage']) ? [] : $configData['storage'];
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
        $componentParameters = new ComponentParameters($this->encryptor, $component['image_parameters'], $sandboxed);
        $authorization = new Authorization($this->oauthClient, $this->encryptor, $componentId, $sandboxed);

        $this->configFile = new ConfigFile(
            $this->dataDirectory->getDataDir(),
            $configData,
            $componentParameters->getComponentParameters(),
            $authorization->getAuthorization(),
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
            $component,
            $configData['processors'],
            $configData
        );

        $this->containerCreator = new ContainerCreator(
            $this->loggerService->getLog(),
            $this->loggerService->getContainerLog(),
            $this->dataDirectory->getDataDir(),
            $this->environment->getEnvironmentVariables()
        );
        switch ($mode) {
            case 'run':
                $componentOutput = $this->runComponent($componentId, $configId);
                break;
            case 'sandbox':
            case 'input':
            case 'dry-run':
                $componentOutput = $this->sandboxComponent($componentId, $mode);
                break;
            default:
                throw new ApplicationException("Invalid run mode " . $mode);
        }
        return $componentOutput;
    }

    public function runComponent($componentId, $configId)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->configFile->createConfigFile();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        // run images
        $componentOutput = '';
        $images = $this->imageCreator->prepareImages();
        $this->loggerService->setVerbosity($images[0]->getLoggerVerbosity());
        $counter = 0;
        foreach ($images as $priority => $image) {
            $containerId = $componentId . '-' . $this->storageClient->getRunId();
            $container = $this->containerCreator->createContainerFromImage($image, $containerId);
            $output = $container->run();
            if ($priority == 0) {
                // image with priority 0 is main image, only its output is really important
                $componentOutput = $output;
            }
            $counter++;
            if ($counter < count($images)) {
                $this->dataDirectory->moveOutputToInput();
            }
        }

        // finalize
        $this->dataLoader->storeOutput();
        if ($this->shouldStoreState($componentId, $configId)) {
            $this->stateFile->storeStateFile();
        }
        $this->dataDirectory->dropDataDir();
        $this->loggerService->getLog()->info("Docker container '$componentId' finished.");
        return $componentOutput;
    }

    public function sandboxComponent($componentId, $mode)
    {
        // initialize
        $this->dataDirectory->createDataDir();
        $this->configFile->createConfigFile();
        $this->stateFile->createStateFile();
        $this->dataLoader->loadInputData();

        // run images
        $componentOutput = '';
        if ($mode == 'dry-run') {
            $images = $this->imageCreator->prepareImages();
            $this->loggerService->setVerbosity($images[0]->getLoggerVerbosity());
            $counter = 0;
            foreach ($images as $priority => $image) {
                $containerId = $componentId . '-' . $this->storageClient->getRunId();
                $container = $this->containerCreator->createContainerFromImage($image, $containerId);
                $output = $container->run();
                if ($priority == 0) {
                    // image with priority 0 is main image, only its output is really important
                    $componentOutput = $output;
                }
                $counter++;
                if ($counter < count($images)) {
                    $this->dataDirectory->moveOutputToInput();
                }
            }
        }

        $this->dataLoader->storeDataArchive([$mode, 'docker', $componentId]);
        // finalize
        $this->dataDirectory->dropDataDir();
        return $componentOutput;
    }
}
