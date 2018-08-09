<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\DockerBundle\Docker\Runner\DataLoader\NullDataLoader;
use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Temp\Temp;

class Runner
{
    const MODE_DEBUG = 'debug';

    const MODE_RUN = 'run';

    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    /**
     * @var Client
     */
    private $storageClient;

    /**
     * @var Credentials
     */
    private $oauthClient;

    /**
     * @var Credentials
     */
    private $oauthClient3;

    /**
     * @var LoggersService
     */
    private $loggersService;

    /**
     * @var string
     */
    private $commandToGetHostIp;

    /**
     * @var int
     */
    private $minLogPort;

    /**
     * @var int
     */
    private $maxLogPort;

    /**
     * @var array
     */
    private $instanceLimits;

    /**
     * Runner constructor.
     * @param ObjectEncryptorFactory $encryptorFactory
     * @param Client $storageApi
     * @param LoggersService $loggersService
     * @param string $oauthApiUrl
     * @param array $instanceLimits
     * @param string $commandToGetHostIp
     * @param int $minLogPort
     * @param int $maxLogPort
     */
    public function __construct(
        ObjectEncryptorFactory $encryptorFactory,
        Client $storageApi,
        LoggersService $loggersService,
        $oauthApiUrl,
        array $instanceLimits,
        $commandToGetHostIp = 'ip -4 addr show docker0 | grep -Po \'inet \K[\d.]+\'',
        $minLogPort = 12202,
        $maxLogPort = 13202
    ) {
        /* the above port range is rather arbitrary, it intentionally excludes the default port (12201)
        to avoid mis-configured clients. */
        $this->encryptorFactory = $encryptorFactory;
        $this->storageClient = $storageApi;
        $this->oauthClient = new Credentials($this->storageClient->getTokenString(), [
            'url' => $oauthApiUrl
        ]);
        $this->oauthClient3 = new Credentials($this->storageClient->getTokenString(), [
            'url' => $this->getOauthUrlV3()
        ]);
        $this->loggersService = $loggersService;
        $this->instanceLimits = $instanceLimits;
        $this->commandToGetHostIp = $commandToGetHostIp;
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
    }

    /**
     * @return string
     */
    private function getOauthUrlV3()
    {
        $services = $this->storageClient->indexAction()['services'];
        foreach ($services as $service) {
            if ($service['id'] == 'oauth') {
                return $service['url'];
            }
        }
        throw new ApplicationException('The oauth service not found.');
    }

    /**
     * @param Image $image
     * @param $containerId
     * @param RunCommandOptions $runCommandOptions
     * @param WorkingDirectory $workingDirectory
     * @param OutputFilterInterface $outputFilter
     * @param Limits $limits
     * @return Container
     */
    private function createContainerFromImage(
        Image $image,
        $containerId,
        RunCommandOptions $runCommandOptions,
        WorkingDirectory $workingDirectory,
        OutputFilterInterface $outputFilter,
        Limits $limits
    ) {
        return new Container(
            $containerId,
            $image,
            $this->loggersService->getLog(),
            $this->loggersService->getContainerLog(),
            $workingDirectory->getDataDir(),
            $workingDirectory->getTmpDir(),
            $this->commandToGetHostIp,
            $this->minLogPort,
            $this->maxLogPort,
            $runCommandOptions,
            $outputFilter,
            $limits
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
     * @param UsageFileInterface $usageFile
     * @return Output
     */
    private function runRow(JobDefinition $jobDefinition, $action, $mode, $jobId, UsageFileInterface $usageFile)
    {
        $this->loggersService->getLog()->notice(
            "Using configuration id: " . $jobDefinition->getConfigId() . ' version:' . $jobDefinition->getConfigVersion()
            . ", row id: " . $jobDefinition->getRowId()
        );
        $component = $jobDefinition->getComponent();
        $this->loggersService->setComponentId($component->getId());

        $temp = new Temp("docker");
        $temp->initRunFolder();
        $workingDirectory = new WorkingDirectory($temp->getTmpFolder(), $this->loggersService->getLog());
        $usageFile->setDataDir($workingDirectory->getDataDir());

        $configData = $jobDefinition->getConfiguration();
        $authorization = new Authorization($this->oauthClient, $this->oauthClient3, $this->encryptorFactory->getEncryptor(), $component->getId());
        $imageParameters = $this->encryptorFactory->getEncryptor()->decrypt($component->getImageParameters());
        $configFile = new ConfigFile(
            $workingDirectory->getDataDir(),
            $imageParameters,
            $authorization,
            $action,
            $component->getConfigurationFormat()
        );

        if (($action == 'run') && ($component->getStagingStorage()['input'] != 'none')) {
            $outputFilter = new OutputFilter();
            $dataLoader = new DataLoader(
                $this->storageClient,
                $this->loggersService->getLog(),
                $workingDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $outputFilter,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId()
            );
        } else {
            $outputFilter = new NullFilter();
            $dataLoader = new NullDataLoader(
                $this->storageClient,
                $this->loggersService->getLog(),
                $workingDirectory->getDataDir(),
                $configData['storage'],
                $component,
                $outputFilter,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId()
            );
        }

        $stateFile = new StateFile(
            $workingDirectory->getDataDir(),
            $this->storageClient,
            $this->encryptorFactory,
            $jobDefinition->getState(),
            $component->getConfigurationFormat(),
            $component->getId(),
            $jobDefinition->getConfigId(),
            $outputFilter,
            $jobDefinition->getRowId()
        );

        $imageCreator = new ImageCreator(
            $this->encryptorFactory->getEncryptor(),
            $this->loggersService->getLog(),
            $this->storageClient,
            $component,
            $configData
        );

        $output = $this->runComponent($jobId, $jobDefinition->getConfigId(), $jobDefinition->getRowId(), $component, $usageFile, $dataLoader, $workingDirectory, $stateFile, $imageCreator, $configFile, $outputFilter, $jobDefinition->getConfigVersion(), $mode);
        return $output;
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @param $action
     * @param $mode
     * @param $jobId
     * @param $usageFile
     * @param string|null $rowId
     * @return Output[]
     */
    public function run(array $jobDefinitions, $action, $mode, $jobId, UsageFileInterface $usageFile, $rowId = null)
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

        if (($mode !== self::MODE_RUN) && ($mode !== self::MODE_DEBUG)) {
            throw new UserException("Invalid run mode: $mode");
        }
        $outputs = [];
        $counter = 0;
        foreach ($jobDefinitions as $jobDefinition) {
            $counter++;
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
            $this->loggersService->getLog()->info(
                "Running component " . $jobDefinition->getComponentId() .
                ' (row ' . $counter . ' of ' . count($jobDefinitions) . ')'
            );
            $outputs[] = $this->runRow($jobDefinition, $action, $mode, $jobId, $usageFile);
            $this->loggersService->getLog()->info(
                "Finished component " . $jobDefinition->getComponentId() .
                ' (row ' . $counter . ' of ' . count($jobDefinitions) . ')'
            );
        }
        return $outputs;
    }

    /**
     * @param $jobId
     * @param $configId
     * @param $rowId
     * @param Component $component
     * @param UsageFileInterface $usageFile
     * @param DataLoaderInterface $dataLoader
     * @param WorkingDirectory $workingDirectory
     * @param StateFile $stateFile
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @param OutputFilterInterface $outputFilter
     * @param string $configVersion
     * @param string $mode
     * @return Output
     * @throws ClientException
     */
    private function runComponent($jobId, $configId, $rowId, Component $component, UsageFileInterface $usageFile, DataLoaderInterface $dataLoader, WorkingDirectory $workingDirectory, StateFile $stateFile, ImageCreator $imageCreator, ConfigFile $configFile, OutputFilterInterface $outputFilter, $configVersion, $mode)
    {
        // initialize
        $workingDirectory->createWorkingDir();
        $dataLoader->loadInputData();

        $output = $this->runImages($jobId, $configId, $rowId, $component, $usageFile, $workingDirectory, $imageCreator, $configFile, $stateFile, $outputFilter, $dataLoader, $configVersion, $mode);

        if ($mode === self::MODE_DEBUG) {
            $dataLoader->storeDataArchive('stage_output', [self::MODE_DEBUG, $component->getId(), 'RowId:' . $rowId, 'JobId:' . $jobId]);
        } else {
            $dataLoader->storeOutput();
        }

        // finalize
        $workingDirectory->dropWorkingDir();
        return $output;
    }

    /**
     * @param $jobId
     * @param $configId
     * @param $rowId
     * @param Component $component
     * @param UsageFileInterface $usageFile
     * @param WorkingDirectory $workingDirectory
     * @param ImageCreator $imageCreator
     * @param ConfigFile $configFile
     * @param StateFile $stateFile
     * @param OutputFilterInterface $outputFilter
     * @param DataLoaderInterface $dataLoader
     * @param string $mode
     * @param string $configVersion
     * @return Output
     * @throws ClientException
     */
    private function runImages($jobId, $configId, $rowId, Component $component, UsageFileInterface $usageFile, WorkingDirectory $workingDirectory, ImageCreator $imageCreator, ConfigFile $configFile, StateFile $stateFile, OutputFilterInterface $outputFilter, DataLoaderInterface $dataLoader, $configVersion, $mode)
    {
        $images = $imageCreator->prepareImages();
        $this->loggersService->setVerbosity($component->getLoggerVerbosity());
        $tokenInfo = $this->storageClient->verifyToken();
        $limits = new Limits(
            $this->loggersService->getLog(),
            $this->instanceLimits,
            !empty($tokenInfo['owner']['limits']) ? $tokenInfo['owner']['limits'] : [],
            !empty($tokenInfo['owner']['features']) ? $tokenInfo['owner']['features'] : [],
            !empty($tokenInfo['admin']['features']) ? $tokenInfo['admin']['features'] : []
        );

        $counter = 0;
        $imageDigests = [];
        $newState = [];
        $outputMessage = '';
        foreach ($images as $priority => $image) {
            if ($image->isMain()) {
                $stateFile->createStateFile();
            } else {
                $this->loggersService->getLog()->info("Running processor " . $image->getSourceComponent()->getId());
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
            $configFile->createConfigFile($image->getConfigData(), $outputFilter);

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
                    $environment->getEnvironmentVariables($outputFilter)
                ),
                $workingDirectory,
                $outputFilter,
                $limits
            );
            if ($mode === self::MODE_DEBUG) {
                $dataLoader->storeDataArchive('stage_' . $priority, [self::MODE_DEBUG, $image->getSourceComponent()->getId(), 'RowId:' . $rowId, 'JobId:' . $jobId, $image->getImageId()]);
            }
            try {
                $process = $container->run();
                if ($image->isMain()) {
                    $outputMessage = $process->getOutput();
                    $newState = $stateFile->loadStateFromFile();
                }
            } finally {
                if ($image->getSourceComponent()->runAsRoot()) {
                    $workingDirectory->normalizePermissions();
                }
                if ($image->isMain()) {
                    $usageFile->storeUsage();
                }
            }
            $counter++;
            if ($counter < count($images)) {
                $workingDirectory->moveOutputToInput();
            }
        }
        if (($mode !== self::MODE_DEBUG) && $this->shouldStoreState($component->getId(), $configId)) {
            $stateFile->storeState($newState);
        }
        return new Output($imageDigests, $outputMessage, $configVersion);
    }
}
