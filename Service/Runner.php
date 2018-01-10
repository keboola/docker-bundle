<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Docker\Component;
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
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;

class Runner
{
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
    private $loggersService;

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
    private $features = [];

    /**
     * @var int
     */
    private $minLogPort;

    /**
     * @var int
     */
    private $maxLogPort;

    /**
     * Runner constructor.
     * @param ObjectEncryptorFactory $encryptorFactory
     * @param StorageApiService $storageApi
     * @param LoggersService $loggersService
     * @param JobMapper $jobMapper
     * @param string $oauthApiUrl
     * @param string $commandToGetHostIp
     * @param int $minLogPort
     * @param int $maxLogPort
     */
    public function __construct(
        ObjectEncryptorFactory $encryptorFactory,
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
        $this->encryptor = $encryptorFactory->getEncryptor();
        $this->storageClient = $storageApi->getClient();
        $this->oauthClient = new Credentials($this->storageClient->getTokenString(), [
            'url' => $oauthApiUrl
        ]);
        $this->oauthClient->enableReturnArrays(true);
        $this->loggersService = $loggersService;
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
            $this->loggersService->getLog(),
            $this->loggersService->getContainerLog(),
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
     * @param string $action
     * @param string $mode
     * @param string $jobId
     * @return Output
     */
    public function runRow(JobDefinition $jobDefinition, $action, $mode, $jobId)
    {
        $this->loggersService->getLog()->notice(
            "Using configuration id: " . $jobDefinition->getConfigId() . ' version:' . $jobDefinition->getConfigVersion()
            . ", row id: " . $jobDefinition->getRowId()
        );
        $component = $jobDefinition->getComponent();
        $this->loggersService->getLog()->info("Running Component " . $component->getId(), $jobDefinition->getConfiguration());
        $this->loggersService->setComponentId($component->getId());

        $configData = $jobDefinition->getConfiguration();

        $temp = new Temp("docker");
        $temp->initRunFolder();
        $dataDirectory = new DataDirectory($temp->getTmpFolder(), $this->loggersService->getLog());
        $stateFile = new StateFile(
            $dataDirectory->getDataDir(),
            $this->storageClient,
            $jobDefinition->getState(),
            $component->getConfigurationFormat(),
            $component->getId(),
            $jobDefinition->getConfigId(),
            $jobDefinition->getRowId()
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
                $this->loggersService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId()
            );
        } else {
            $dataLoader = new NullDataLoader(
                $this->storageClient,
                $this->loggersService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId()
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
                $this->loggersService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId()
            );
        } else {
            $dataLoader = new NullDataLoader(
                $this->storageClient,
                $this->loggersService->getLog(),
                $dataDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId()
            );
        }
        $dataLoader->setFeatures($this->features);
        $imageCreator = new ImageCreator(
            $this->encryptor,
            $this->loggersService->getLog(),
            $this->storageClient,
            $component,
            $configData
        );

        switch ($mode) {
            case 'run':
                $output = $this->runComponent($jobId, $jobDefinition->getConfigId(), $jobDefinition->getRowId(), $component, $usageFile, $dataLoader, $dataDirectory, $stateFile, $imageCreator, $configFile);
                break;
            case 'sandbox':
            case 'input':
            case 'dry-run':
                $output = $this->sandboxComponent($jobId, $configData, $mode, $jobDefinition->getConfigId(), $component, $usageFile, $dataLoader, $dataDirectory, $stateFile, $imageCreator, $configFile);
                break;
            default:
                throw new ApplicationException("Invalid run mode " . $mode);
        }
        return $output;
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @param $action
     * @param $mode
     * @param $jobId
     * @param string|null $rowId
     * @return array
     */
    public function run(array $jobDefinitions, $action, $mode, $jobId, $rowId = null)
    {
        if ($rowId) {
            $jobDefinitions = array_filter($jobDefinitions, function ($jobDefinition) use ($rowId) {
                /**
                 * @var JobDefinition $jobDefinition
                 */
                return $jobDefinition->getRowId() === $rowId;
            });
            if (count($jobDefinitions) === 0) {
                throw new UserException("Row {$rowId} not found.");
            }
        }

        if (count($jobDefinitions) > 1 && $mode != 'run') {
            throw new UserException('Only 1 row allowed for sandbox calls.');
        }
        $outputs = [];
        foreach ($jobDefinitions as $jobDefinition) {
            if ($jobDefinition->isDisabled()) {
                if ($rowId !== null) {
                    $this->loggersService->getLog()->info(
                        "Force running disabled configuration: " . $jobDefinition->getConfigId()
                        . ', version: ' . $jobDefinition->getConfigVersion()
                        . ", row: " . $jobDefinition->getRowId()
                    );
                } else {
                    $this->loggersService->getLog()->info(
                        "Skipping disabled configuration: " . $jobDefinition->getConfigId()
                        . ', version: ' . $jobDefinition->getConfigVersion()
                        . ", row: " . $jobDefinition->getRowId()
                    );
                    continue;
                }
            }
            $outputs[] = $this->runRow($jobDefinition, $action, $mode, $jobId);
        }
        return $outputs;
    }

    /**
     * @param $jobId
     * @param $configId
     * @param $rowId
     * @param Component $component
     * @param UsageFile $usageFile
     * @param DataLoaderInterface $dataLoader
     * @param DataDirectory $dataDirectory
     * @param StateFile $stateFile
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @return Output
     */
    public function runComponent($jobId, $configId, $rowId, Component $component, UsageFile $usageFile, DataLoaderInterface $dataLoader, DataDirectory $dataDirectory, StateFile $stateFile, ImageCreator $imageCreator, ConfigFile $configFile)
    {
        // initialize
        $dataDirectory->createDataDir();
        $stateFile->createStateFile();
        $dataLoader->loadInputData();

        $output = $this->runImages($jobId, $configId, $rowId, $component, $usageFile, $dataDirectory, $imageCreator, $configFile, $stateFile);

        // finalize
        $dataLoader->storeOutput();

        $dataDirectory->dropDataDir();
        $this->loggersService->getLog()->info("Component " . $component->getId() . " finished.");
        return $output;
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
     * @return Output
     */
    public function sandboxComponent($jobId, $configData, $mode, $configId, Component $component, UsageFile $usageFile, DataLoaderInterface $dataLoader, DataDirectory $dataDirectory, StateFile $stateFile, ImageCreator $imageCreator, ConfigFile $configFile)
    {
        // initialize
        $dataDirectory->createDataDir();
        $stateFile->createStateFile();
        $dataLoader->loadInputData();

        if ($mode == 'dry-run') {
            $output = $this->runImages($jobId, $configId, 'NA', $component, $usageFile, $dataDirectory, $imageCreator, $configFile, $stateFile);
        } else {
            $configFile->createConfigFile($configData);
            $output = new Output();
        }

        $dataLoader->storeDataArchive([$mode, 'docker', $component->getId()]);
        // finalize
        $dataDirectory->dropDataDir();
        return $output;
    }

    /**
     * @param $jobId
     * @param $configId
     * @param $rowId
     * @param Component $component
     * @param UsageFile $usageFile
     * @param DataDirectory $dataDirectory
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @param StateFile $stateFile
     * @return Output
     */
    private function runImages($jobId, $configId, $rowId, Component $component, UsageFile $usageFile, DataDirectory $dataDirectory, ImageCreator $imageCreator, ConfigFile $configFile, StateFile $stateFile)
    {
        $images = $imageCreator->prepareImages();
        $this->loggersService->setVerbosity($component->getLoggerVerbosity());
        $tokenInfo = $this->storageClient->verifyToken();

        $counter = 0;
        $imageDigests = [];
        $outputMessage = '';
        foreach ($images as $priority => $image) {
            if (!$image->isMain()) {
                $this->loggersService->getLog()->info("Running processor " . $image->getFullImageId());
            }
            $environment = new Environment(
                $configId,
                $image->getSourceComponent(),
                $image->getConfigData()['parameters'],
                $tokenInfo,
                $this->storageClient->getRunId(),
                $this->storageClient->getApiUrl()
            );
            $imageDigests[] = [
                'id' => $image->getFullImageId(),
                'digests' => $image->getImageDigests()
            ];
            $configFile->createConfigFile($image->getConfigData());

            $containerIdParts[] = $jobId;
            $containerIdParts[] = $this->storageClient->getRunId() ?: 'norunid';
            $containerIdParts[] = $rowId;
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
                        'com.keboola.docker-runner.rowId=' . $rowId,
                        'com.keboola.docker-runner.containerName=' . join('-', $containerNameParts)
                    ],
                    $environment->getEnvironmentVariables()
                ),
                $dataDirectory
            );
            try {
                $process = $container->run();
                if ($image->isMain()) {
                    $outputMessage = $process->getOutput();
                    if ($this->shouldStoreState($component->getId(), $configId)) {
                        $stateFile->storeStateFile();
                    }
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
        return new Output($imageDigests, $outputMessage);
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
