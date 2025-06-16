<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\Artifacts\Artifacts;
use Keboola\Artifacts\ArtifactsException;
use Keboola\Artifacts\Tags;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Docker\Runner\DataLoader\InputDataLoaderFactory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\OutputDataLoaderFactory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\StagingWorkspaceFacade;
use Keboola\DockerBundle\Docker\Runner\DataLoader\StagingWorkspaceFacadeFactory;
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
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration as JobConfiguration;
use Keboola\JobQueue\JobConfiguration\JobDefinition\State\State;
use Keboola\JobQueue\JobConfiguration\Mapping\DataDirUploader;
use Keboola\JobQueue\JobConfiguration\Mapping\InputDataLoader;
use Keboola\JobQueue\JobConfiguration\Mapping\OutputDataLoader;
use Keboola\KeyGenerator\PemKeyCertificateGenerator;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\StagingProvider\Workspace\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Temp\Temp;
use Throwable;

class Runner
{
    private const MODE_DEBUG = 'debug';
    private const MODE_RUN = 'run';

    private ObjectEncryptor $encryptor;
    private ClientWrapper $clientWrapper;
    private Credentials $oauthClient3;
    private LoggersService $loggersService;
    private string $commandToGetHostIp;
    private int $minLogPort;
    private int $maxLogPort;
    private array $instanceLimits;
    private OutputFilterInterface $outputFilter;
    private ?StagingWorkspaceFacade $stagingWorkspace = null;

    public function __construct(
        ObjectEncryptor $encryptor,
        ClientWrapper $clientWrapper,
        LoggersService $loggersService,
        OutputFilterInterface $outputFilter,
        array $instanceLimits,
        int $minLogPort = 12202,
        int $maxLogPort = 13202,
    ) {
        /* the above port range is rather arbitrary, it intentionally excludes the default port (12201)
        to avoid mis-configured clients. */
        $this->encryptor = $encryptor;
        $this->clientWrapper = $clientWrapper;
        $this->outputFilter = $outputFilter;

        $storageApiClient = $clientWrapper->getBasicClient();
        $storageApiToken = $storageApiClient->getTokenString();

        $this->oauthClient3 = new Credentials($storageApiToken, [
            'url' => $this->getOauthUrlV3(),
        ]);
        $this->loggersService = $loggersService;
        $this->instanceLimits = $instanceLimits;
        $this->commandToGetHostIp = $this->getCommandToGetHostIp();
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
        $this->outputFilter = $outputFilter;
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
        Limits $limits,
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
            $limits,
        );
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @return bool
     * @throws ClientException
     */
    private function shouldStoreState(array $jobDefinitions)
    {
        $jobDefinition = reset($jobDefinitions);
        if (!$jobDefinition) {
            return false;
        }
        $componentId = $jobDefinition->getComponentId();
        $configurationId = $jobDefinition->getConfigId();
        $storeState = false;
        if ($componentId && $configurationId) {
            $storeState = true;

            // Do not store state if configuration does not exist
            $components = new Components($this->clientWrapper->getBranchClient());
            try {
                $components->getConfiguration($componentId, $configurationId);
            } catch (ClientException $e) {
                if ($e->getStringCode() === 'notFound' && $e->getPrevious()->getCode() === 404) {
                    $storeState = false;
                } else {
                    throw $e;
                }
            }
        }
        return $storeState;
    }

    public function cleanUp(): void
    {
        /* This method is expected to be called from pcntl signal termination handler, which means that runs with
            the main thread paused and expecting it not to be resumed. */
        $this->stagingWorkspace?->cleanup();
    }

    private function runRow(
        JobDefinition $jobDefinition,
        string $action,
        string $mode,
        string $jobId,
        UsageFileInterface $usageFile,
        array &$outputs,
        ?string $backendSize,
        bool $storeState,
        ?string $orchestrationId,
    ) {
        $temp = new Temp();
        $workingDirectory = new WorkingDirectory($temp->getTmpFolder(), $this->loggersService->getLog());
        $this->loggersService->getLog()->notice(
            'Using configuration id: ' . $jobDefinition->getConfigId() .
            ' version:' . $jobDefinition->getConfigVersion()
            . ', row id: ' . $jobDefinition->getRowId() . ', state: ' . json_encode($jobDefinition->getState())
            . ', tmp folder: ' . $workingDirectory->getDataDir(),
        );

        $currentOutput = new Output();
        $outputs[] = $currentOutput;
        $currentOutput->setConfigVersion($jobDefinition->getConfigVersion());
        $component = $jobDefinition->getComponent();
        $this->loggersService->setComponentId($component->getId());

        if ($jobDefinition->getInputVariableValues()) {
            $currentOutput->setInputVariableValues($jobDefinition->getInputVariableValues());
        }

        $usageFile->setDataDir($workingDirectory->getDataDir());

        $tokenInfo = $this->clientWrapper->getBranchClient()->verifyToken();
        $jobScopedEncryptor = new JobScopedEncryptor(
            $this->encryptor,
            $jobDefinition->getComponentId(),
            (string) $tokenInfo['owner']['id'],
            $jobDefinition->getConfigId(),
            $jobDefinition->getBranchType(),
            $tokenInfo['owner']['features'],
        );

        $configData = $jobDefinition->getConfiguration();
        $authorization = new Authorization($this->oauthClient3, $jobScopedEncryptor, $component->getId());

        $configFile = new ConfigFile(
            $workingDirectory->getDataDir(),
            $authorization,
            $action,
            $component->getConfigurationFormat(),
        );

        if (($action === 'run') && ($component->getStagingStorage()['input'] !== 'none')) {
            $jobConfiguration = JobConfiguration::fromArray($jobDefinition->getConfiguration());
            $jobState = State::fromArray($jobDefinition->getState());

            // setup staging workspace
            $workspaceProvider = new WorkspaceProvider(
                new Workspaces($this->clientWrapper->getBranchClient()),
                new Components($this->clientWrapper->getBranchClient()),
                new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
            );

            $stagingWorkspaceFactory = new StagingWorkspaceFacadeFactory(
                $workspaceProvider,
                $this->loggersService->getLog(),
            );

            $this->stagingWorkspace = $stagingWorkspaceFactory->createStagingWorkspaceFacade(
                $this->clientWrapper->getToken(),
                $component,
                $jobConfiguration,
                $jobDefinition->getConfigId(),
            );

            // setup input-mapping
            $inputDataLoaderFactory = new InputDataLoaderFactory(
                $workspaceProvider,
                $this->loggersService->getLog(),
                $workingDirectory->getDataDir(),
            );
            $inputDataLoader = $inputDataLoaderFactory->createInputDataLoader(
                $this->clientWrapper,
                $component,
                $jobConfiguration,
                $jobState,
                $this->stagingWorkspace->getWorkspaceId(),
            );

            // setup output-mapping
            $outputDataLoaderFactory = new OutputDataLoaderFactory(
                $workspaceProvider,
                $this->loggersService->getLog(),
                $workingDirectory->getDataDir(),
            );
            $outputDataLoader = $outputDataLoaderFactory->createOutputDataLoader(
                $this->clientWrapper,
                $component,
                $jobConfiguration,
                $jobDefinition->getConfigId(),
                $jobDefinition->getRowId(),
                $this->stagingWorkspace->getWorkspaceId(),
            );
        } else {
            $this->stagingWorkspace = null;
            $inputDataLoader = null;
            $outputDataLoader = null;
        }

        $dataDirUploader = new DataDirUploader(
            $this->clientWrapper->getBranchClient(),
            $this->outputFilter,
        );

        $stateFile = new StateFile(
            $workingDirectory->getDataDir(),
            $this->clientWrapper,
            $jobScopedEncryptor,
            $jobDefinition->getState(),
            $component->getConfigurationFormat(),
            $component->getId(),
            $jobDefinition->getConfigId() ? (string) $jobDefinition->getConfigId() : null,
            $this->outputFilter,
            $this->loggersService->getLog(),
            $jobDefinition->getRowId() ? (string) $jobDefinition->getRowId() : null,
        );
        $currentOutput->setStateFile($stateFile);

        $artifacts = new Artifacts(
            $this->clientWrapper,
            $this->loggersService->getLog(),
            $temp,
        );

        $imageCreator = new ImageCreator(
            $this->loggersService->getLog(),
            $this->clientWrapper->getBranchClient(),
            $component,
            $configData,
        );

        try {
            $this->runComponent(
                $jobId,
                $jobDefinition->getConfigId(),
                $jobDefinition->getConfigVersion(),
                $jobDefinition->getRowId(),
                $component,
                $usageFile,
                $this->stagingWorkspace,
                $inputDataLoader,
                $outputDataLoader,
                $dataDirUploader,
                $workingDirectory,
                $stateFile,
                $imageCreator,
                $configFile,
                $this->outputFilter,
                $mode,
                $currentOutput,
                $artifacts,
                $backendSize,
                $storeState,
                $orchestrationId,
                $jobScopedEncryptor,
            );
        } catch (Throwable $e) {
            $this->stagingWorkspace?->cleanup();
            throw $e;
        } finally {
            $this->stagingWorkspace = null;
        }
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     */
    public function run(
        array $jobDefinitions,
        string $action,
        string $mode,
        string $jobId,
        UsageFileInterface $usageFile,
        array $rowIds,
        array &$outputs,
        ?string $backendSize,
        ?string $orchestrationId = null,
    ): void {
        if ($rowIds) {
            $jobDefinitions = array_filter($jobDefinitions, function ($jobDefinition) use ($rowIds) {
                return in_array($jobDefinition->getRowId(), $rowIds);
            });
            if (count($jobDefinitions) === 0) {
                throw new UserException(sprintf('None of rows "%s" was found.', implode(',', $rowIds)));
            }
        }

        if (($mode !== self::MODE_RUN) && ($mode !== self::MODE_DEBUG)) {
            throw new UserException("Invalid run mode: $mode");
        }
        $storeState = $this->shouldStoreState($jobDefinitions);
        $outputs = [];
        $counter = 0;
        foreach ($jobDefinitions as $jobDefinition) {
            $counter++;
            if ($jobDefinition->isDisabled()) {
                if (in_array($jobDefinition->getRowId(), $rowIds)) {
                    $this->loggersService->getLog()->info(
                        'Force running disabled configuration: ' . $jobDefinition->getConfigId()
                        . ', version: ' . $jobDefinition->getConfigVersion()
                        . ', row: ' . $jobDefinition->getRowId(),
                    );
                } else {
                    $this->loggersService->getLog()->info(
                        'Skipping disabled configuration: ' . $jobDefinition->getConfigId()
                        . ', version: ' . $jobDefinition->getConfigVersion()
                        . ', row: ' . $jobDefinition->getRowId(),
                    );
                    continue;
                }
            }
            $this->loggersService->getLog()->info(
                'Running component ' . $jobDefinition->getComponentId() .
                ' (row ' . $counter . ' of ' . count($jobDefinitions) . ')',
            );

            $this->runRow(
                $jobDefinition,
                $action,
                $mode,
                $jobId,
                $usageFile,
                $outputs,
                $backendSize,
                $storeState,
                $orchestrationId,
            );
            $this->loggersService->getLog()->info(
                'Finished component ' . $jobDefinition->getComponentId() .
                ' (row ' . $counter . ' of ' . count($jobDefinitions) . ')',
            );
        }
        $this->waitForStorageJobs($outputs);
        /** @var Output $output */
        foreach ($outputs as $output) {
            if (($mode !== self::MODE_DEBUG) && $storeState) {
                $output->getStateFile()->persistState(
                    $output->getInputTableResult()?->getInputTableStateList() ?? new InputTableStateList([]),
                    $output->getInputFileStateList() ?? new InputFileStateList([]),
                );
            }
        }
    }

    private function waitForStorageJobs(array $outputs): void
    {
        /** @var Output[] $outputsWithTableQueue */
        $outputsWithTableQueue = [];
        $taskCount = 0;
        try {
            foreach ($outputs as $output) {
                /** @var Output $output */
                if ($output->getTableQueue()) {
                    $outputsWithTableQueue[] = $output;
                    $taskCount += $output->getTableQueue()->getTaskCount();
                }
            }
            $this->loggersService->getLog()->info(sprintf('Waiting for %s Storage jobs to finish.', $taskCount));
            foreach ($outputsWithTableQueue as $output) {
                try {
                    $output->getTableQueue()->waitForAll();
                } catch (InvalidOutputException $e) {
                    throw new UserException('Failed to process output mapping: ' . $e->getMessage(), $e);
                }

                $output->setOutputTableResult($output->getTableQueue()->getTableResult());
            }
        } finally {
            foreach ($outputs as $output) {
                $output->getStagingWorkspace()?->cleanup();
            }
            $this->loggersService->getLog()->info('Output mapping done.');
        }
    }

    private function runComponent(
        string $jobId,
        ?string $configId,
        ?string $configVersion,
        ?string $rowId,
        ComponentSpecification $component,
        UsageFileInterface $usageFile,
        ?StagingWorkspaceFacade $stagingWorkspace,
        ?InputDataLoader $inputDataLoader,
        ?OutputDataLoader $outputDataLoader,
        DataDirUploader $dataDirUploader,
        WorkingDirectory $workingDirectory,
        StateFile $stateFile,
        ImageCreator $imageCreator,
        ConfigFile $configFile,
        OutputFilterInterface $outputFilter,
        string $mode,
        Output $output,
        Artifacts $artifacts,
        ?string $backendSize,
        bool $storeState,
        ?string $orchestrationId,
        JobScopedEncryptor $jobScopedEncryptor,
    ): Output {
        // initialize
        $workingDirectory->createWorkingDir();
        $inputMappingResult = $inputDataLoader?->loadInputData();

        try {
            $this->runImages(
                $jobId,
                $configId,
                $configVersion,
                $rowId,
                $component,
                $usageFile,
                $workingDirectory,
                $imageCreator,
                $configFile,
                $stateFile,
                $outputFilter,
                $inputDataLoader,
                $stagingWorkspace,
                $dataDirUploader,
                $mode,
                $output,
                $artifacts,
                $backendSize,
                $storeState,
                $orchestrationId,
                $jobScopedEncryptor,
            );

            $output->setStagingWorkspace($stagingWorkspace);

            if ($mode === self::MODE_DEBUG) {
                $dataDirUploader->uploadDataDir(
                    $jobId,
                    $component->getId(),
                    $rowId,
                    $workingDirectory->getDataDir(),
                    'stage_output',
                );
            } else {
                $tableQueue = $outputDataLoader?->storeOutput();
                $output->setTableQueue($tableQueue);
            }

            // finalize
            $workingDirectory->dropWorkingDir();
            return $output;
        } catch (Throwable $exception) {
            if ($mode !== self::MODE_DEBUG) {
                try {
                    $tableQueue = $outputDataLoader?->storeOutput(true);
                    $output->setTableQueue($tableQueue);
                    $output->setStagingWorkspace($stagingWorkspace);
                    $this->waitForStorageJobs([$output]);
                } catch (Throwable) {
                    throw $exception;
                }
            }
            throw $exception;
        } finally {
            if ($inputMappingResult !== null) {
                $output->setInputTableResult($inputMappingResult->inputTableResult);
                $output->setInputFileStateList($inputMappingResult->inputFileStateList);
            }
        }
    }

    private function runImages(
        string $jobId,
        ?string $configId,
        ?string $configVersion,
        ?string $rowId,
        ComponentSpecification $component,
        UsageFileInterface $usageFile,
        WorkingDirectory $workingDirectory,
        ImageCreator $imageCreator,
        ConfigFile $configFile,
        StateFile $stateFile,
        OutputFilterInterface $outputFilter,
        ?InputDataLoader $inputDataLoader,
        ?StagingWorkspaceFacade $stagingWorkspace,
        DataDirUploader $dataDirUploader,
        string $mode,
        Output $output,
        Artifacts $artifacts,
        ?string $backendSize,
        bool $storeState,
        ?string $orchestrationId,
        JobScopedEncryptor $jobScopedEncryptor,
    ): void {
        $images = $imageCreator->prepareImages();
        $this->loggersService->setVerbosity($component->getLoggerVerbosity());
        $tokenInfo = $this->clientWrapper->getBranchClient()->verifyToken();
        $limits = new Limits(
            $this->loggersService->getLog(),
            $this->instanceLimits,
            !empty($tokenInfo['owner']['limits']) ? $tokenInfo['owner']['limits'] : [],
            !empty($tokenInfo['owner']['features']) ? $tokenInfo['owner']['features'] : [],
            $backendSize,
        );

        $counter = 0;
        $imageDigests = [];
        $newState = [];
        $output->setOutput('');

        foreach ($images as $priority => $image) {
            if ($image->isMain()) {
                $stateFile->createStateFile();
                if ($storeState && in_array('artifacts', $tokenInfo['owner']['features'])) {
                    $downloadedArtifacts = $artifacts->download(
                        new Tags(
                            $this->clientWrapper->getBranchId(),
                            $component->getId(),
                            $configId,
                            $jobId,
                            $orchestrationId,
                        ),
                        $image->getConfigData(),
                    );
                    $output->setArtifactsDownloaded($downloadedArtifacts);
                }
            } else {
                $this->loggersService->getLog()->info('Running processor ' . $image->getSourceComponent()->getId());
                if ($inputDataLoader === null) {
                    // there is nothing reasonable a processor can do because there's no data
                    continue;
                }
            }
            $environment = new Environment(
                $configId,
                $configVersion,
                $rowId,
                $image->getSourceComponent(),
                $image->getConfigData()['parameters'],
                $tokenInfo,
                $this->clientWrapper->getBranchClient()->getRunId(),
                $this->clientWrapper->getBranchClient()->getApiUrl(),
                $this->clientWrapper->getBranchClient()->getTokenString(),
                $this->clientWrapper->getBranchId(),
                $mode,
                $component->getDataTypesSupport(),
            );
            $imageDigests[] = [
                'id' => $image->getPrintableImageId(),
                'digests' => $image->getPrintableImageDigests(),
            ];
            $output->setImages($imageDigests);
            $configFile->createConfigFile(
                $image->getConfigData(),
                $outputFilter,
                $stagingWorkspace?->getCredentials() ?? [],
                $jobScopedEncryptor->decrypt($image->getSourceComponent()->getImageParameters()),
            );

            $containerIdParts = [
                $jobId,
                $this->clientWrapper->getBranchClient()->getRunId() ?: 'norunid',
                $rowId,
                $priority,
                $image->getSourceComponent()->getSanitizedComponentId(),
            ];
            $containerNameParts = [
                $image->getSourceComponent()->getSanitizedComponentId(),
                $jobId,
                $this->clientWrapper->getBranchClient()->getRunId() ?: 'norunid',
                $priority,
            ];

            $container = $this->createContainerFromImage(
                $image,
                join('-', $containerIdParts),
                new RunCommandOptions(
                    [
                        'com.keboola.docker-runner.jobId=' . $jobId,
                        'com.keboola.docker-runner.runId=' .
                            ($this->clientWrapper->getBranchClient()->getRunId() ?: 'norunid'),
                        'com.keboola.docker-runner.rowId=' . $rowId,
                        'com.keboola.docker-runner.containerName=' . join('-', $containerNameParts),
                        'com.keboola.docker-runner.projectId=' . $tokenInfo['owner']['id'],
                    ],
                    $environment->getEnvironmentVariables($outputFilter),
                ),
                $workingDirectory,
                $outputFilter,
                $limits,
            );
            if ($mode === self::MODE_DEBUG) {
                $dataDirUploader->uploadDataDir(
                    $jobId,
                    $component->getId(),
                    $rowId,
                    $workingDirectory->getDataDir(),
                    'stage_' . $priority,
                );
            }
            try {
                $process = $container->run();
                if ($image->isMain()) {
                    $output->setOutput($process->getOutput());
                    $newState = $stateFile->loadStateFromFile();
                    if ($storeState && in_array('artifacts', $tokenInfo['owner']['features'])) {
                        try {
                            $uploadedArtifacts = $artifacts->upload(new Tags(
                                $this->clientWrapper->getBranchId(),
                                $component->getId(),
                                $configId,
                                $jobId,
                                $orchestrationId,
                            ), $image->getConfigData());
                            $output->setArtifactsUploaded($uploadedArtifacts);
                        } catch (ArtifactsException $e) {
                            $this->loggersService->getLog()->warning(
                                sprintf('Error uploading artifacts "%s"', $e->getMessage()),
                            );
                        }
                    }
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
