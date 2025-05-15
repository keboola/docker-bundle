<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Options\InputTableOptionsList;
use Keboola\InputMapping\Table\Options\ReaderOptions;
use Keboola\InputMapping\Table\Result as InputTableResult;
use Keboola\KeyGenerator\PemKeyCertificateGenerator;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\OutputMappingSettings;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\OutputMapping\SystemMetadata;
use Keboola\OutputMapping\TableLoader;
use Keboola\OutputMapping\Writer\FileWriter;
use Keboola\StagingProvider\Staging\File\LocalStaging;
use Keboola\StagingProvider\Staging\StagingClass;
use Keboola\StagingProvider\Staging\StagingProvider;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Staging\Workspace\LazyWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\NullWorkspaceStaging;
use Keboola\StagingProvider\Workspace\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;
use ZipArchive;

class DataLoader implements DataLoaderInterface
{
    private array $storageConfig;
    private array $runtimeConfig;
    private string $defaultBucketName;
    private Component $component;
    private ?string $configId;
    private ?string $configRowId;
    private InputStrategyFactory $inputStrategyFactory;
    private OutputStrategyFactory $outputStrategyFactory;
    private StagingProvider $stagingProvider;

    public function __construct(
        private readonly ClientWrapper $clientWrapper,
        private readonly LoggerInterface $logger,
        private readonly string $dataDirectory,
        JobDefinition $jobDefinition,
        private readonly OutputFilterInterface $outputFilter,
    ) {
        $configuration = $jobDefinition->getConfiguration();
        $this->storageConfig = $configuration['storage'] ?? [];
        $this->runtimeConfig = $configuration['runtime'] ?? [];
        $this->component = $jobDefinition->getComponent();
        $this->configId = $jobDefinition->getConfigId();
        $this->configRowId = $jobDefinition->getRowId();
        $this->defaultBucketName = (string) ($this->storageConfig['output']['default_bucket'] ?? '');
        if ($this->defaultBucketName === '') {
            $this->defaultBucketName = $this->getDefaultBucket();
        }

        $inputStagingType = $this->getStagingStorageInput();
        $outputStagingType = $this->getStagingStorageOutput();
        $this->validateStagingSetting($inputStagingType, $outputStagingType);

        $externallyManagedWorkspaceCredentials = $this->getExternallyManagedWorkspaceCredentials($this->runtimeConfig);

        /* dataDirectory is "something/data" - this https://github.com/keboola/docker-bundle/blob/f9d4cf0d0225097ba4e5a1952812c405e333ce72/src/Docker/Runner/WorkingDirectory.php#L90
            we need the base dir here */
        $dataDirectory = dirname($this->dataDirectory);

        $componentsApiClient = new Components($this->clientWrapper->getBranchClient());
        $workspacesApiClient = new Workspaces($this->clientWrapper->getBranchClient());
        $snowflakeKeypairGenerator = new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator());

        $workspaceProviderFactory = new WorkspaceProviderFactory(
            $this->logger,
        );

        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
           just input staging here (because if it is a workspace, it must be the same as output mapping). */
        if ($inputStagingType->getStagingClass() === StagingClass::Workspace) {
            $storageApiToken = $this->clientWrapper->getToken();

            $workspaceProviderConfig = $workspaceProviderFactory->getWorkspaceProviderConfig(
                $storageApiToken,
                $inputStagingType,
                $this->component,
                $this->configId,
                $this->runtimeConfig['backend'] ?? [],
                $this->storageConfig['input']['read_only_storage_access'] ?? null,
                $externallyManagedWorkspaceCredentials,
            );

            $workspaceProvider = new WorkspaceProvider(
                $workspacesApiClient,
                $componentsApiClient,
                $snowflakeKeypairGenerator,
            );

            $stagingWorkspace = new LazyWorkspaceStaging(
                $workspaceProvider,
                $workspaceProviderConfig,
            );
        } else {
            $stagingWorkspace = new NullWorkspaceStaging();
        }

        $inputStagingProvider = new StagingProvider(
            $inputStagingType,
            $stagingWorkspace,
            new LocalStaging($dataDirectory),
        );
        $this->stagingProvider = $inputStagingProvider;
        $this->inputStrategyFactory = new InputStrategyFactory(
            $inputStagingProvider,
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat(),
        );

        $outputStagingProvider = new StagingProvider(
            $outputStagingType,
            $stagingWorkspace,
            new LocalStaging($dataDirectory),
        );
        $this->outputStrategyFactory = new OutputStrategyFactory(
            $outputStagingProvider,
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat(),
        );
    }

    /**
     * Download source files
     */
    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
    ): StorageState {
        $reader = new Reader(
            $this->clientWrapper,
            $this->logger,
            $this->inputStrategyFactory,
        );
        $inputTableResult = new InputTableResult();
        $inputTableResult->setInputTableStateList(new InputTableStateList([]));
        $resultInputFilesStateList = new InputFileStateList([]);
        $readerOptions = new ReaderOptions(
            !$this->component->allowBranchMapping(),
            preserveWorkspace: false,
        );

        try {
            if (isset($this->storageConfig['input']['tables']) && count($this->storageConfig['input']['tables'])) {
                $this->logger->debug('Downloading source tables.');
                $inputTableResult = $reader->downloadTables(
                    new InputTableOptionsList($this->storageConfig['input']['tables']),
                    $inputTableStateList,
                    'data/in/tables/',
                    $readerOptions,
                );
            }
            if (isset($this->storageConfig['input']['files']) &&
                count($this->storageConfig['input']['files'])
            ) {
                $this->logger->debug('Downloading source files.');
                $resultInputFilesStateList = $reader->downloadFiles(
                    $this->storageConfig['input']['files'],
                    'data/in/files/',
                    $inputFileStateList,
                );
            }
        } catch (ClientException $e) {
            throw new UserException('Cannot import data from Storage API: ' . $e->getMessage(), $e);
        } catch (InvalidInputException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        return new StorageState($inputTableResult, $resultInputFilesStateList);
    }

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue
    {
        $this->logger->debug('Storing results.');
        $outputTablesConfig = [];
        $outputFilesConfig = [];
        $outputTableFilesConfig = [];

        if (isset($this->storageConfig['output']['tables']) &&
            count($this->storageConfig['output']['tables'])
        ) {
            $outputTablesConfig = $this->storageConfig['output']['tables'];
        }
        if (isset($this->storageConfig['output']['files']) &&
            count($this->storageConfig['output']['files'])
        ) {
            $outputFilesConfig = $this->storageConfig['output']['files'];
        }
        if (isset($this->storageConfig['output']['table_files']) &&
            count($this->storageConfig['output']['table_files'])
        ) {
            $outputTableFilesConfig = $this->storageConfig['output']['table_files'];
        }
        $this->logger->debug('Uploading output tables and files.');

        $uploadTablesOptions = ['mapping' => $outputTablesConfig];

        $commonSystemMetadata = [
            SystemMetadata::SYSTEM_KEY_COMPONENT_ID => $this->component->getId(),
            SystemMetadata::SYSTEM_KEY_CONFIGURATION_ID => $this->configId,
        ];
        if ($this->configRowId) {
            $commonSystemMetadata[SystemMetadata::SYSTEM_KEY_CONFIGURATION_ROW_ID] = $this->configRowId;
        }
        $tableSystemMetadata = $fileSystemMetadata = $commonSystemMetadata;
        if ($this->clientWrapper->isDevelopmentBranch()) {
            $tableSystemMetadata[SystemMetadata::SYSTEM_KEY_BRANCH_ID] = $this->clientWrapper->getBranchId();
        }

        $fileSystemMetadata[SystemMetadata::SYSTEM_KEY_RUN_ID] = $this->clientWrapper->getBranchClient()->getRunId();

        // Get default bucket
        if ($this->defaultBucketName) {
            $uploadTablesOptions['bucket'] = $this->defaultBucketName;
            $this->logger->debug('Default bucket ' . $uploadTablesOptions['bucket']);
        }

        $treatValuesAsNull = $this->storageConfig['output']['treat_values_as_null'] ?? null;
        if ($treatValuesAsNull !== null) {
            $uploadTablesOptions['treat_values_as_null'] = $treatValuesAsNull;
        }

        try {
            $fileWriter = new FileWriter(
                $this->clientWrapper,
                $this->logger,
                $this->outputStrategyFactory,
            );
            $fileWriter->uploadFiles(
                'data/out/files/',
                ['mapping' => $outputFilesConfig],
                $fileSystemMetadata,
                [],
                $isFailedJob,
            );
            if ($this->useFileStorageOnly()) {
                $fileWriter->uploadFiles(
                    'data/out/tables/',
                    [],
                    $fileSystemMetadata,
                    $outputTableFilesConfig,
                    $isFailedJob,
                );
                if (isset($this->storageConfig['input']['files'])) {
                    // tag input files
                    $fileWriter->tagFiles($this->storageConfig['input']['files']);
                }
                return null;
            }

            $tableLoader = new TableLoader(
                logger: $this->logger,
                clientWrapper: $this->clientWrapper,
                strategyFactory: $this->outputStrategyFactory,
            );

            $mappingSettings = new OutputMappingSettings(
                configuration: $uploadTablesOptions,
                sourcePathPrefix: 'data/out/tables/',
                storageApiToken: $this->clientWrapper->getToken(),
                isFailedJob: $isFailedJob,
                dataTypeSupport: $this->getDataTypeSupport(),
            );

            $tableQueue = $tableLoader->uploadTables(
                configuration: $mappingSettings,
                systemMetadata: new SystemMetadata($tableSystemMetadata),
            );

            if (isset($this->storageConfig['input']['files']) && !$isFailedJob) {
                // tag input files
                $fileWriter->tagFiles($this->storageConfig['input']['files']);
            }
            return $tableQueue;
        } catch (InvalidOutputException $ex) {
            throw new UserException($ex->getMessage(), $ex);
        }
    }

    private function useFileStorageOnly(): bool
    {
        return $this->component->allowUseFileStorageOnly() && isset($this->runtimeConfig['use_file_storage_only']);
    }

    public function getWorkspaceBackendSize(): ?string
    {
        return $this->stagingProvider->getWorkspaceStaging()?->getBackendSize();
    }

    public function getWorkspaceCredentials(): array
    {
        return $this->stagingProvider->getWorkspaceStaging()?->getCredentials() ?? [];
    }

    /**
     * Archive data directory and save it to Storage
     */
    public function storeDataArchive(string $fileName, array $tags): void
    {
        $zip = new ZipArchive();
        $zipFileName = $this->dataDirectory . DIRECTORY_SEPARATOR . $fileName . '.zip';
        $zip->open($zipFileName, ZipArchive::CREATE);
        $finder = new Finder();
        /** @var SplFileInfo $item */
        foreach ($finder->in($this->dataDirectory) as $item) {
            if ($item->isDir()) {
                if (!$zip->addEmptyDir($item->getRelativePathname())) {
                    throw new ApplicationException('Failed to add directory: ' . $item->getFilename());
                }
            } else {
                if ($item->getPathname() === $zipFileName) {
                    continue;
                }
                if (($item->getRelativePathname() === 'config.json') ||
                    ($item->getRelativePathname() === 'state.json')
                ) {
                    $configData = file_get_contents($item->getPathname());
                    $configData = $this->outputFilter->filter($configData);
                    if (!$zip->addFromString($item->getRelativePathname(), $configData)) {
                        throw new ApplicationException('Failed to add file: ' . $item->getFilename());
                    }
                } elseif (!$zip->addFile($item->getPathname(), $item->getRelativePathname())) {
                    throw new ApplicationException('Failed to add file: ' . $item->getFilename());
                }
            }
        }
        $zip->close();
        $uploadOptions = new FileUploadOptions();
        $uploadOptions->setTags($tags);
        $uploadOptions->setIsPermanent(false);
        $uploadOptions->setIsPublic(false);
        $uploadOptions->setNotify(false);

        $this->clientWrapper->getTableAndFileStorageClient()->uploadFile($zipFileName, $uploadOptions);

        $fs = new Filesystem();
        $fs->remove($zipFileName);
    }

    protected function getDefaultBucket(): string
    {
        if ($this->component->hasDefaultBucket()) {
            if (!$this->configId) {
                throw new UserException('Configuration ID not set, but is required for default_bucket option.');
            }
            return $this->component->getDefaultBucketName($this->configId);
        } else {
            return '';
        }
    }

    private function getStagingStorageInput(): StagingType
    {
        $stagingStorage = $this->component->getStagingStorage();
        if (isset($stagingStorage['input'])) {
            return StagingType::from($stagingStorage['input']);
        }

        return StagingType::Local;
    }

    private function getStagingStorageOutput(): StagingType
    {
        $stagingStorage = $this->component->getStagingStorage();
        if (isset($stagingStorage['output'])) {
            return StagingType::from($stagingStorage['output']);
        }

        return StagingType::Local;
    }

    private function validateStagingSetting(StagingType $inputStagingType, StagingType $outputStagingType): void
    {
        if ($inputStagingType->getStagingClass() === StagingClass::Workspace
            && $outputStagingType->getStagingClass() === StagingClass::Workspace
            && $inputStagingType !== $outputStagingType
        ) {
            throw new ApplicationException(sprintf(
                'Component staging setting mismatch - input: "%s", output: "%s".',
                $inputStagingType->value,
                $outputStagingType->value,
            ));
        }
    }

    public function cleanWorkspace(): void
    {
        try {
            $this->stagingProvider->getWorkspaceStaging()?->cleanup();
        } catch (Throwable $e) {
            // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
            $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    public function getDataTypeSupport(): string
    {
        if (!$this->clientWrapper->getToken()->hasFeature('new-native-types')) {
            return 'none';
        }
        return $this->storageConfig['output']['data_type_support'] ?? $this->component->getDataTypesSupport();
    }

    private function getExternallyManagedWorkspaceCredentials(
        array $runtimeConfig,
    ): ?ExternallyManagedWorkspaceCredentials {
        if (isset($runtimeConfig['backend']['workspace_credentials'])) {
            return ExternallyManagedWorkspaceCredentials::fromArray($runtimeConfig['backend']['workspace_credentials']);
        }
        return null;
    }
}
