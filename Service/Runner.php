<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\DockerBundle\Docker\Runner\DataLoader\NullDataLoader;
use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Elasticsearch\JobMapper;
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
     * @var string
     */
    private $commandToGetHostIp;

    /**
     * @var JobMapper
     */
    private $jobMapper;

    /**
     * @var array
     */
    protected $features = [];

    /**
     * Runner constructor.
     * @param Temp $temp
     * @param ObjectEncryptor $encryptor
     * @param StorageApiService $storageApi
     * @param LoggersService $loggersService
     * @param JobMapper $jobMapper
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
        JobMapper $jobMapper,
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
        $this->jobMapper = $jobMapper;
        $this->commandToGetHostIp = $commandToGetHostIp;
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
    }

    /**
     * @param Image $image
     * @param $containerId
     * @param RunCommandOptions $runCommandOptions
     * @param DataDirectory $dataDirectory
     * @return Container
     */
    private function createContainerFromImage(Image $image, $containerId, RunCommandOptions $runCommandOptions, DataDirectory $dataDirectory)
    {
        return new Container(
            $containerId,
            $image,
            $this->loggerService->getLog(),
            $this->loggerService->getContainerLog(),
            $dataDirectory->getDataDir(),
            $this->commandToGetHostIp,
            $this->minLogPort,
            $this->maxLogPort,
            $runCommandOptions
        );
    }

    /**
     * @param $componentId
     * @param $configurationId
     * @return bool
     * @throws ClientException
     */
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
     * @param JobDefinition $jobDefinition
     * @param $action
     * @param $mode
     * @param $jobId
     * @param Output $output
     */
    public function runRow(JobDefinition $jobDefinition, $action, $mode, $jobId, Output &$output)
    {
        $this->loggerService->getLog()->notice(
            "Using configuration id: " . $jobDefinition->getConfigId() . ' version:' . $jobDefinition->getConfigVersion()
            . ", rowId: " . $jobDefinition->getRowId() . ' version: ' . $jobDefinition->getRowVersion()
        );
        $component = $jobDefinition->getComponent();
        $this->loggerService->getLog()->info("Running Component " . $component->getId(), $jobDefinition->getConfiguration());
        $this->loggerService->setComponentId($component->getId());

        $configData = $jobDefinition->getConfiguration();

        $dataDirectory = new DataDirectory($this->temp->getTmpFolder(), $this->loggerService->getLog());
        $stateFile = new StateFile(
            $dataDirectory->getDataDir(),
            $this->storageClient,
            $jobDefinition->getState(),
            $component->getId(),
            $jobDefinition->getConfigId(),
            $component->getConfigurationFormat()
        );


        $usageFile = new UsageFile(
            $dataDirectory->getDataDir(),
            $component->getConfigurationFormat(),
            $this->jobMapper,
            $jobId
        );

        if (($action == 'run') && ($component->getStagingStorage()['input'] != 'none')) {
            $dataLoader = new DataLoader(
                $this->storageClient,
                $this->loggerService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId()
            );
        } else {
            $dataLoader = new NullDataLoader(
                $this->storageClient,
                $this->loggerService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId()
            );
        }
        $dataLoader->setFeatures($this->features);

        $sandboxed = $mode != 'run';
        $configData = $jobDefinition->getConfiguration();

        $authorization = new Authorization($this->oauthClient, $this->encryptor, $component->getId(), $sandboxed);

        if ($sandboxed) {
            // do not decrypt image parameters on sandboxed calls
            $imageParameters = $component->getImageParameters();
        } else {
            $imageParameters = $this->encryptor->decrypt($component->getImageParameters());
        }
        $configFile = new ConfigFile(
            $dataDirectory->getDataDir(),
            $imageParameters,
            $authorization,
            $action,
            $component->getConfigurationFormat()
        );

        if (($action == 'run') && ($component->getStagingStorage()['input'] != 'none')) {
            $dataLoader = new DataLoader(
                $this->storageClient,
                $this->loggerService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId()
            );
        } else {
            $dataLoader = new NullDataLoader(
                $this->storageClient,
                $this->loggerService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId()
            );
        }
        $dataLoader->setFeatures($this->features);
        $imageCreator = new ImageCreator(
            $this->encryptor,
            $this->loggerService->getLog(),
            $this->storageClient,
            $component,
            $configData
        );

        switch ($mode) {
            case 'run':
                $this->runComponent($jobId, $jobDefinition->getConfigId(), $component, $usageFile, $dataLoader, $dataDirectory, $stateFile, $imageCreator, $configFile, $output);
                break;
            case 'sandbox':
            case 'input':
            case 'dry-run':
                $this->sandboxComponent($jobId, $configData, $mode, $jobDefinition->getConfigId(), $component, $usageFile, $dataLoader, $dataDirectory, $stateFile, $imageCreator, $configFile, $output);
                break;
            default:
                throw new ApplicationException("Invalid run mode " . $mode);
        }
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @param $action
     * @param $mode
     * @param $jobId
     * @return Output
     */
    public function run(array $jobDefinitions, $action, $mode, $jobId)
    {
        if (count($jobDefinitions) > 1 && $mode != 'run') {
            throw new UserException('Only 1 row allowed for sandbox calls.');
        }
        $componentOutput = new Output();
        foreach ($jobDefinitions as $jobDefinition) {
            if ($jobDefinition->isDisabled()) {
                $this->loggerService->getLog()->notice(
                    "Skipping configuration id: " . $jobDefinition->getConfigId() . ' version:' . $jobDefinition->getConfigVersion()
                    . ", rowId: " . $jobDefinition->getRowId() . ' version: ' . $jobDefinition->getRowVersion()
                );
                continue;
            }
            $this->runRow($jobDefinition, $action, $mode, $jobId, $componentOutput);
        }
        return $componentOutput;
    }

    /**
     * @param $jobId
     * @param $configId
     * @param Component $component
     * @param UsageFile $usageFile
     * @param DataLoaderInterface $dataLoader
     * @param DataDirectory $dataDirectory
     * @param StateFile $stateFile
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @param Output $output
     */
    public function runComponent($jobId, $configId, Component $component, UsageFile $usageFile, DataLoaderInterface $dataLoader, DataDirectory $dataDirectory, StateFile $stateFile, ImageCreator $imageCreator, ConfigFile $configFile, Output &$output)
    {
        // initialize
        $dataDirectory->createDataDir();
        $stateFile->createStateFile();
        $dataLoader->loadInputData();

        $this->runImages($jobId, $configId, $component, $usageFile, $dataDirectory, $imageCreator, $configFile, $output);

        // finalize
        $dataLoader->storeOutput();
        if ($this->shouldStoreState($component->getId(), $configId)) {
            $stateFile->storeStateFile();
        }

        $dataDirectory->dropDataDir();
        $this->loggerService->getLog()->info("Component " . $component->getId() . " finished.");
    }

    /**
     * @param $jobId
     * @param $configData
     * @param $mode
     * @param $configId
     * @param Component $component
     * @param UsageFile $usageFile
     * @param DataLoaderInterface $dataLoader
     * @param DataDirectory $dataDirectory
     * @param StateFile $stateFile
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @param Output $output
     */
    public function sandboxComponent($jobId, $configData, $mode, $configId, Component $component, UsageFile $usageFile, DataLoaderInterface $dataLoader, DataDirectory $dataDirectory, StateFile $stateFile, ImageCreator $imageCreator, ConfigFile $configFile, Output &$output)
    {
        // initialize
        $dataDirectory->createDataDir();
        $stateFile->createStateFile();
        $dataLoader->loadInputData();

        if ($mode == 'dry-run') {
            $this->runImages($jobId, $configId, $component, $usageFile, $dataDirectory, $imageCreator, $configFile, $output);
        } else {
            $configFile->createConfigFile($configData);
        }

        $dataLoader->storeDataArchive([$mode, 'docker', $component->getId()]);
        // finalize
        $dataDirectory->dropDataDir();
    }

    /**
     * @param $jobId
     * @param $configId
     * @param Component $component
     * @param UsageFile $usageFile
     * @param DataDirectory $dataDirectory
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @param Output $output
     */
    private function runImages($jobId, $configId, Component $component, UsageFile $usageFile, DataDirectory $dataDirectory, ImageCreator $imageCreator, ConfigFile $configFile, Output &$output)
    {
        $images = $imageCreator->prepareImages();
        $this->loggerService->setVerbosity($component->getLoggerVerbosity());
        $tokenInfo = $this->storageClient->verifyToken();

        $counter = 0;
        foreach ($images as $priority => $image) {
            if (!$image->isMain()) {
                $this->loggerService->getLog()->info("Running processor " . $image->getFullImageId());
            }
            $environment = new Environment(
                $configId,
                $image->getSourceComponent(),
                $image->getConfigData()['parameters'],
                $tokenInfo,
                $this->storageClient->getRunId(),
                $this->storageClient->getApiUrl()
            );
            $output->addImages($priority, $image->getFullImageId(), $image->getImageDigests());
            $configFile->createConfigFile($image->getConfigData());

            $containerIdParts = [];
            $containerIdParts[] = $jobId;
            $containerIdParts[] = $this->storageClient->getRunId() ?: 'norunid';
            $containerIdParts[] = $priority;
            $containerIdParts[] = $image->getSourceComponent()->getSanitizedComponentId();

            $containerNameParts[] = $image->getSourceComponent()->getSanitizedComponentId();
            $containerNameParts[] = $jobId;
            $containerNameParts[] = $this->storageClient->getRunId() ?: 'norunid';
            $containerNameParts[] = $priority;

            $container = $this->createContainerFromImage(
                $image,
                join('-', $containerIdParts),
                new RunCommandOptions(
                    [
                        'com.keboola.docker-runner.jobId=' . $jobId,
                        'com.keboola.docker-runner.runId=' . ($this->storageClient->getRunId() ?: 'norunid'),
                        'com.keboola.docker-runner.containerName=' . join('-', $containerNameParts)
                    ],
                    $environment->getEnvironmentVariables()
                ),
                $dataDirectory
            );
            try {
                $process = $container->run();
                if ($image->isMain()) {
                    $output->addProcessOutput($process->getOutput());
                }
            } finally {
                $dataDirectory->normalizePermissions();
                if ($image->isMain()) {
                    $usageFile->storeUsage();
                }
            }
            $counter++;
            if ($counter < count($images)) {
                $dataDirectory->moveOutputToInput();
            }
        }
    }

    /**
     * @param array $features
     * @return $this
     */
    public function setFeatures($features)
    {
        $this->features = $features;

        return $this;
    }
}
