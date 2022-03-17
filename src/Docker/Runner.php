<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\DockerBundle\Docker\Runner\DataLoader\NullDataLoader;
use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\Sandboxes\Api\Client as SandboxesApiClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\IndexOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Temp\Temp;

class Runner
{
    const MODE_DEBUG = 'debug';

    const MODE_RUN = 'run';

    private ObjectEncryptorFactory $encryptorFactory;
    private ClientWrapper $clientWrapper;
    private Credentials $oauthClient;
    private Credentials $oauthClient3;
    private MlflowProjectResolver $mlflowProjectResolver;
    private LoggersService $loggersService;
    private string $commandToGetHostIp;
    private int $minLogPort;
    private int $maxLogPort;
    private array $instanceLimits;

    public function __construct(
        ObjectEncryptorFactory $encryptorFactory,
        ClientWrapper $clientWrapper,
        LoggersService $loggersService,
        string $oauthApiUrl,
        array $instanceLimits,
        int $minLogPort = 12202,
        int $maxLogPort = 13202
    ) {
        /* the above port range is rather arbitrary, it intentionally excludes the default port (12201)
        to avoid mis-configured clients. */
        $this->encryptorFactory = $encryptorFactory;
        $this->clientWrapper = $clientWrapper;

        $storageApiClient = $clientWrapper->getBasicClient();
        $storageApiToken = $storageApiClient->getTokenString();

        $sandboxesApiClient = new SandboxesApiClient(
            $storageApiClient->getServiceUrl('sandboxes'),
            $storageApiToken
        );

        $this->oauthClient = new Credentials($storageApiToken, [
            'url' => $oauthApiUrl
        ]);
        $this->oauthClient3 = new Credentials($storageApiToken, [
            'url' => $this->getOauthUrlV3()
        ]);
        $this->mlflowProjectResolver = new MlflowProjectResolver(
            $storageApiClient,
            $sandboxesApiClient,
            $loggersService->getLog()
        );
        $this->loggersService = $loggersService;
        $this->instanceLimits = $instanceLimits;
        $this->commandToGetHostIp = $this->getCommandToGetHostIp();
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
    }

    private function getCommandToGetHostIp()
    {
        if (getenv('RUNNER_COMMAND_TO_GET_HOST_IP')) {
            return getenv('RUNNER_COMMAND_TO_GET_HOST_IP');
        }

        return 'ip -4 addr show docker0 | grep -Po \'inet \K[\d.]+\'';
    }

    /**
     * @return string
     */
    private function getOauthUrlV3()
    {
        try {
            return $this->clientWrapper->getBasicClient()->getServiceUrl('oauth');
        } catch (ClientException $e) {
            throw new ApplicationException(sprintf('The "oauth" service not found: %s', $e->getMessage()), $e);
        }
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
            $components = new Components($this->clientWrapper->getBasicClient());
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
     * @param Output[] $outputs
     */
    private function runRow(JobDefinition $jobDefinition, $action, $mode, $jobId, UsageFileInterface $usageFile, array &$outputs)
    {
        $this->loggersService->getLog()->notice(
            "Using configuration id: " . $jobDefinition->getConfigId() . ' version:' . $jobDefinition->getConfigVersion()
            . ", row id: " . $jobDefinition->getRowId() . ", state: " . json_encode($jobDefinition->getState())
        );
        $currentOutput = new Output();
        $outputs[] = $currentOutput;
        $currentOutput->setConfigVersion($jobDefinition->getConfigVersion());
        $component = $jobDefinition->getComponent();
        $this->loggersService->setComponentId($component->getId());

        $temp = new Temp();
        $temp->initRunFolder();
        if ($mode === 'j2jRun') {
            $workingDirectory = new WorkingDirectory('/tmp/j2j', $this->loggersService->getLog());
        } else {
            $workingDirectory = new WorkingDirectory($temp->getTmpFolder(), $this->loggersService->getLog());
        }
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
                $this->clientWrapper,
                $this->loggersService->getLog(),
                $workingDirectory->getDataDir(),
                $jobDefinition,
                $outputFilter
            );
        } else {
            $outputFilter = new NullFilter();
            $dataLoader = new NullDataLoader(
                $this->clientWrapper,
                $this->loggersService->getLog(),
                $workingDirectory->getDataDir(),
                $jobDefinition,
                $outputFilter
            );
        }

        $stateFile = new StateFile(
            $workingDirectory->getDataDir(),
            $this->clientWrapper,
            $this->encryptorFactory,
            $jobDefinition->getState(),
            $component->getConfigurationFormat(),
            $component->getId(),
            $jobDefinition->getConfigId(),
            $outputFilter,
            $this->loggersService->getLog(),
            $jobDefinition->getRowId()
        );
        $currentOutput->setStateFile($stateFile);

        $imageCreator = new ImageCreator(
            $this->encryptorFactory->getEncryptor(),
            $this->loggersService->getLog(),
            $this->clientWrapper->getBasicClient(),
            $component,
            $configData
        );

        if (isset($jobDefinition->getState()[StateFile::NAMESPACE_STORAGE][StateFile::NAMESPACE_INPUT][StateFile::NAMESPACE_TABLES])) {
            $inputTableStateList = new InputTableStateList(
                $jobDefinition->getState()[StateFile::NAMESPACE_STORAGE][StateFile::NAMESPACE_INPUT][StateFile::NAMESPACE_TABLES]
            );
        } else {
            $inputTableStateList = new InputTableStateList([]);
        }
        if (isset($jobDefinition->getState()[StateFile::NAMESPACE_STORAGE][StateFile::NAMESPACE_INPUT][StateFile::NAMESPACE_FILES])) {
            $inputFileStateList = new InputFileStateList(
                $jobDefinition->getState()[StateFile::NAMESPACE_STORAGE][StateFile::NAMESPACE_INPUT][StateFile::NAMESPACE_FILES]
            );
        } else {
            $inputFileStateList = new InputFileStateList([]);
        }

        try {
            $this->runComponent(
                $jobId,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId(),
                $component,
                $usageFile,
                $dataLoader,
                $workingDirectory,
                $stateFile,
                $imageCreator,
                $configFile,
                $outputFilter,
                $mode,
                $inputTableStateList,
                $inputFileStateList,
                $currentOutput
            );
        } catch (\Exception $e) {
            $dataLoader->cleanWorkspace();
            throw $e;
        }
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @param string $action
     * @param string $mode
     * @param string $jobId
     * @param UsageFileInterface $usageFile
     * @param array $rowIds
     * @param Output[] $outputs
     */
    public function run(array $jobDefinitions, $action, $mode, $jobId, UsageFileInterface $usageFile, array $rowIds, array &$outputs)
    {
        if ($rowIds) {
            $jobDefinitions = array_filter($jobDefinitions, function ($jobDefinition) use ($rowIds) {
                /**
                 * @var JobDefinition $jobDefinition
                 */
                return in_array($jobDefinition->getRowId(), $rowIds);
            });
            if (count($jobDefinitions) === 0) {
                throw new UserException(sprintf('None of rows "%s" was found.', implode(',', $rowIds)));
            }
        }

        $outputs = [];
        $counter = 0;
        foreach ($jobDefinitions as $jobDefinition) {
            $counter++;
            if ($jobDefinition->isDisabled()) {
                if (in_array($jobDefinition->getRowId(), $rowIds)) {
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
            $this->runRow($jobDefinition, $action, $mode, $jobId, $usageFile, $outputs);
            $this->loggersService->getLog()->info(
                "Finished component " . $jobDefinition->getComponentId() .
                ' (row ' . $counter . ' of ' . count($jobDefinitions) . ')'
            );
        }
        $this->waitForStorageJobs($outputs);
        /** @var Output $output */
        foreach ($outputs as $output) {
            if (($mode !== self::MODE_DEBUG) && $this->shouldStoreState($jobDefinition->getComponentId(), $jobDefinition->getConfigId())) {
                $output->getStateFile()->persistState(
                    $output->getInputTableResult()->getInputTableStateList(),
                    $output->getInputFileStateList()
                );
            }
        }
    }

    private function waitForStorageJobs(array $outputs)
    {
        $tableQueues = [];
        $taskCount = 0;
        try {
            foreach ($outputs as $output) {
                /** @var Output $output */
                if ($output->getTableQueue()) {
                    $tableQueues[] = $output->getTableQueue();
                    $taskCount += $output->getTableQueue()->getTaskCount();
                }
            }
            $this->loggersService->getLog()->info(sprintf('Waiting for %s Storage jobs to finish.', $taskCount));
            /** @var LoadTableQueue $tableQueue */
            foreach ($tableQueues as $tableQueue) {
                try {
                    $tableQueue->waitForAll();
                } catch (InvalidOutputException $e) {
                    throw new UserException('Failed to process output mapping: ' . $e->getMessage(), $e);
                }
            }
        } finally {
            foreach ($outputs as $output) {
                $output->getDataLoader()->cleanWorkspace();
            }
            $this->loggersService->getLog()->info('Output mapping done.');
        }
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
     * @param string $mode
     * @param InputTableStateList $inputTableStateList
     * @param InputFileStateList $inputFileStateList
     * @param Output $output
     * @throws ClientException
     */
    private function runComponent(
        $jobId,
        $configId,
        $rowId,
        Component $component,
        UsageFileInterface $usageFile,
        DataLoaderInterface $dataLoader,
        WorkingDirectory $workingDirectory,
        StateFile $stateFile,
        ImageCreator $imageCreator,
        ConfigFile $configFile,
        OutputFilterInterface $outputFilter,
        $mode,
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
        Output $output
    ) {
        // initialize
        $workingDirectory->createWorkingDir();
        $storageState = $dataLoader->loadInputData($inputTableStateList, $inputFileStateList);

        $this->runImages($jobId, $configId, $rowId, $component, $usageFile, $workingDirectory, $imageCreator, $configFile, $stateFile, $outputFilter, $dataLoader, $mode, $output);
        $output->setInputTableResult($storageState->getInputTableResult());
        $output->setInputFileStateList($storageState->getInputFileStateList());
        $output->setDataLoader($dataLoader);

        if ($mode === self::MODE_DEBUG) {
            $dataLoader->storeDataArchive('stage_output', [self::MODE_DEBUG, $component->getId(), 'RowId:' . $rowId, 'JobId:' . $jobId]);
        } else {
            $tableQueue = $dataLoader->storeOutput();
            $output->setTableQueue($tableQueue);
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
     * @param Output $output
     * @throws ClientException
     */
    private function runImages(
        $jobId,
        $configId,
        $rowId,
        Component $component,
        UsageFileInterface $usageFile,
        WorkingDirectory $workingDirectory,
        ImageCreator $imageCreator,
        ConfigFile $configFile,
        StateFile $stateFile,
        OutputFilterInterface $outputFilter,
        DataLoaderInterface $dataLoader,
        $mode,
        $output
    ) {
        $images = $imageCreator->prepareImages();
        $this->loggersService->setVerbosity($component->getLoggerVerbosity());
        $tokenInfo = $this->clientWrapper->getBasicClient()->verifyToken();
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
        $absConnectionString = null;
        $mlflowUri = null;
        $output->setOutput('');


        $mlflowProject = $this->mlflowProjectResolver->getMlflowProjectIfAvailable($component, $tokenInfo);
        if ($mlflowProject !== null) {
            $absConnectionString = $mlflowProject->getMlflowAbsConnectionString();
            $mlflowUri = $mlflowProject->getMlflowUri();
        }

        foreach ($images as $priority => $image) {
            if ($image->isMain()) {
                $stateFile->createStateFile();
            } else {
                $this->loggersService->getLog()->info("Running processor " . $image->getSourceComponent()->getId());
                if ($dataLoader instanceof NullDataLoader) {
                    // there is nothing reasonable a processor can do because there's no data
                    continue;
                }
            }
            $environment = new Environment(
                $configId,
                $rowId,
                $image->getSourceComponent(),
                $image->getConfigData()['parameters'],
                $tokenInfo,
                $this->clientWrapper->getBasicClient()->getRunId(),
                $this->clientWrapper->getBasicClient()->getApiUrl(),
                $this->clientWrapper->getBasicClient()->getTokenString(),
                $this->clientWrapper->getBranchId(),
                $absConnectionString,
                $mlflowUri
            );
            $imageDigests[] = [
                'id' => $image->getPrintableImageId(),
                'digests' => $image->getPrintableImageDigests()
            ];
            $output->setImages($imageDigests);
            $configFile->createConfigFile($image->getConfigData(), $outputFilter, $dataLoader->getWorkspaceCredentials());

            $containerIdParts = [
                $jobId,
                $this->clientWrapper->getBasicClient()->getRunId() ?: 'norunid',
                $rowId,
                $priority,
                $image->getSourceComponent()->getSanitizedComponentId()
            ];
            $containerNameParts = [
                $image->getSourceComponent()->getSanitizedComponentId(),
                $jobId,
                $this->clientWrapper->getBasicClient()->getRunId() ?: 'norunid',
                $priority,
            ];

            $container = $this->createContainerFromImage(
                $image,
                join('-', $containerIdParts),
                new RunCommandOptions(
                    [
                        'com.keboola.docker-runner.jobId=' . $jobId,
                        'com.keboola.docker-runner.runId=' . ($this->clientWrapper->getBasicClient()->getRunId() ?: 'norunid'),
                        'com.keboola.docker-runner.rowId=' . $rowId,
                        'com.keboola.docker-runner.containerName=' . join('-', $containerNameParts),
                        'com.keboola.docker-runner.projectId=' . $tokenInfo['owner']['id']
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
                    $output->setOutput($process->getOutput());
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
        $stateFile->stashState($newState);
    }
}
