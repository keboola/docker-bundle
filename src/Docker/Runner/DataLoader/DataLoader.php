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
use Keboola\InputMapping\Staging\AbstractStagingDefinition;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\InputMapping\Staging\ProviderInterface;
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
use Keboola\StagingProvider\InputProviderInitializer;
use Keboola\StagingProvider\OutputProviderInitializer;
use Keboola\StagingProvider\Provider\LocalStagingProvider;
use Keboola\StagingProvider\Provider\NewWorkspaceProvider;
use Keboola\StagingProvider\Provider\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Provider\WorkspaceProviderInterface;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
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
        $this->validateStagingSetting();
        $externallyManagedWorkspaceCredentials = $this->getExternallyManagedWorkspaceCredentials($this->runtimeConfig);

        $this->inputStrategyFactory = new InputStrategyFactory(
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat(),
        );

        $this->outputStrategyFactory = new OutputStrategyFactory(
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat(),
        );

        $tokenInfo = $this->clientWrapper->getBranchClient()->verifyToken();

        /* dataDirectory is "something/data" - this https://github.com/keboola/docker-bundle/blob/f9d4cf0d0225097ba4e5a1952812c405e333ce72/src/Docker/Runner/WorkingDirectory.php#L90
            we need the base dir here */
        $dataDirectory = dirname($this->dataDirectory);

        $componentsApiClient = new Components($this->clientWrapper->getBranchClient());
        $workspacesApiClient = new Workspaces($this->clientWrapper->getBranchClient());

        $workspaceProviderFactory = new WorkspaceProviderFactory(
            $componentsApiClient,
            $workspacesApiClient,
            new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
            $this->logger,
        );
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        $workspaceProvider = $workspaceProviderFactory->getWorkspaceStaging(
            $this->getStagingStorageInput(),
            $this->component,
            $this->configId,
            $this->runtimeConfig['backend'] ?? [],
            $this->storageConfig['input']['read_only_storage_access'] ?? null,
            $externallyManagedWorkspaceCredentials,
        );
        $localProviderFactory = new LocalStagingProvider($dataDirectory);

        $inputProviderInitializer = new InputProviderInitializer(
            $this->inputStrategyFactory,
            $workspaceProvider,
            $localProviderFactory,
        );
        $inputProviderInitializer->initializeProviders(
            $this->getStagingStorageInput(),
            $tokenInfo,
        );

        $outputProviderInitializer = new OutputProviderInitializer(
            $this->outputStrategyFactory,
            $workspaceProvider,
            $localProviderFactory,
        );
        $outputProviderInitializer->initializeProviders(
            $this->getStagingStorageOutput(),
            $tokenInfo,
        );
    }

    /**
     * Download source files
     */
    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
    ): StorageState {
        $reader = new Reader($this->inputStrategyFactory);
        $inputTableResult = new InputTableResult();
        $inputTableResult->setInputTableStateList(new InputTableStateList([]));
        $resultInputFilesStateList = new InputFileStateList([]);
        $readerOptions = new ReaderOptions(
            !$this->component->allowBranchMapping(),
            /* preserve is true only for ABS Workspaces which are persistent (shared across runs) (preserve = true).
                Redshift workspaces are reusable, but still cleaned up before each run (preserve = false).
                Other workspaces (Snowflake, Local) are ephemeral (thus the preserver flag is irrelevant for them).
            */
            $this->getStagingStorageInput() === AbstractStrategyFactory::WORKSPACE_ABS,
        );

        try {
            if (isset($this->storageConfig['input']['tables']) && count($this->storageConfig['input']['tables'])) {
                $this->logger->debug('Downloading source tables.');
                $inputTableResult = $reader->downloadTables(
                    new InputTableOptionsList($this->storageConfig['input']['tables']),
                    $inputTableStateList,
                    'data/in/tables/',
                    $this->getStagingStorageInput(),
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
                    $this->getStagingStorageInput(),
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
            $fileWriter = new FileWriter($this->outputStrategyFactory);
            $fileWriter->uploadFiles(
                'data/out/files/',
                ['mapping' => $outputFilesConfig],
                $fileSystemMetadata,
                $this->getStagingStorageOutput(),
                [],
                $isFailedJob,
            );
            if ($this->useFileStorageOnly()) {
                $fileWriter->uploadFiles(
                    'data/out/tables/',
                    [],
                    $fileSystemMetadata,
                    $this->getStagingStorageOutput(),
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
                logger: $this->outputStrategyFactory->getLogger(),
                clientWrapper: $this->outputStrategyFactory->getClientWrapper(),
                strategyFactory: $this->outputStrategyFactory,
            );

            $mappingSettings = new OutputMappingSettings(
                configuration: $uploadTablesOptions,
                sourcePathPrefix: 'data/out/tables/',
                storageApiToken: $this->outputStrategyFactory->getClientWrapper()->getToken(),
                isFailedJob: $isFailedJob,
                dataTypeSupport: $this->getDataTypeSupport(),
            );

            $tableQueue = $tableLoader->uploadTables(
                outputStaging: $this->getStagingStorageOutput(),
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
        // this returns the first workspace found, which is ok so far because there can only be one
        // (ensured in validateStagingSetting()) working only with inputStrategyFactory, but
        // the workspace providers are shared between input and output, so it's "ok"
        foreach ($this->inputStrategyFactory->getStrategyMap() as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceProviderInterface) {
                    continue;
                }

                return $stagingProvider->getBackendSize();
            }
        }

        return null;
    }

    public function getWorkspaceCredentials(): array
    {
        // this returns the first workspace found, which is ok so far because there can only be one
        // (ensured in validateStagingSetting()) working only with inputStrategyFactory, but
        // the workspace providers are shared between input and output, so it's "ok"
        foreach ($this->inputStrategyFactory->getStrategyMap() as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceProviderInterface) {
                    continue;
                }

                return $stagingProvider->getCredentials();
            }
        }
        return [];
    }

    /**
     * @return iterable<ProviderInterface>
     */
    private function getStagingProviders(AbstractStagingDefinition $stagingDefinition): iterable
    {
        yield $stagingDefinition->getFileDataProvider();
        yield $stagingDefinition->getFileMetadataProvider();
        yield $stagingDefinition->getTableDataProvider();
        yield $stagingDefinition->getTableMetadataProvider();
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

    private function getStagingStorageInput(): string
    {
        $stagingStorage = $this->component->getStagingStorage();
        if ($stagingStorage !== null) {
            if (isset($stagingStorage['input'])) {
                return $stagingStorage['input'];
            }
        }
        return AbstractStrategyFactory::LOCAL;
    }

    private function getStagingStorageOutput(): string
    {
        $stagingStorage = $this->component->getStagingStorage();
        if ($stagingStorage !== null) {
            if (isset($stagingStorage['output'])) {
                return $stagingStorage['output'];
            }
        }
        return AbstractStrategyFactory::LOCAL;
    }

    private function validateStagingSetting(): void
    {
        $workspaceTypes = [AbstractStrategyFactory::WORKSPACE_ABS, AbstractStrategyFactory::WORKSPACE_REDSHIFT,
            AbstractStrategyFactory::WORKSPACE_SNOWFLAKE, AbstractStrategyFactory::WORKSPACE_SYNAPSE,
            AbstractStrategyFactory::WORKSPACE_BIGQUERY];
        if (in_array($this->getStagingStorageInput(), $workspaceTypes)
            && in_array($this->getStagingStorageOutput(), $workspaceTypes)
            && $this->getStagingStorageInput() !== $this->getStagingStorageOutput()
        ) {
            throw new ApplicationException(sprintf(
                'Component staging setting mismatch - input: "%s", output: "%s".',
                $this->getStagingStorageInput(),
                $this->getStagingStorageOutput(),
            ));
        }
    }

    public function cleanWorkspace(): void
    {
        $cleanedProviders = [];
        $maps = array_merge(
            $this->inputStrategyFactory->getStrategyMap(),
            $this->outputStrategyFactory->getStrategyMap(),
        );
        foreach ($maps as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof NewWorkspaceProvider) {
                    continue;
                }
                if (in_array($stagingProvider, $cleanedProviders, true)) {
                    continue;
                }
                /* don't clean ABS workspaces or Redshift workspaces which are reusable if created for a config.

                    The whole condition and the isReusableWorkspace method can probably be completely removed,
                    because now it is distinguished between NewWorkspaceStagingProvider (cleanup) and
                    ExistingWorkspaceStagingProvider (no cleanup).

                    However, since ABS and Redshift workspaces are not used in real life and badly tested, I don't
                    want to remove it now.
                 */
                if ($this->configId && $this->isReusableWorkspace()) {
                    continue;
                }

                try {
                    $stagingProvider->cleanup();
                    $cleanedProviders[] = $stagingProvider;
                } catch (ClientException $e) {
                    if ($e->getCode() === 404) {
                        // workspace is already deleted
                        continue;
                    }
                    // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
                    $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage());
                }
            }
        }
    }

    public function getDataTypeSupport(): string
    {
        if (!$this->clientWrapper->getToken()->hasFeature('new-native-types')) {
            return 'none';
        }
        return $this->storageConfig['output']['data_type_support'] ?? $this->component->getDataTypesSupport();
    }

    private function isReusableWorkspace(): bool
    {
        return $this->getStagingStorageInput() === AbstractStrategyFactory::WORKSPACE_ABS ||
            $this->getStagingStorageOutput() === AbstractStrategyFactory::WORKSPACE_ABS ||
            $this->getStagingStorageInput() === AbstractStrategyFactory::WORKSPACE_REDSHIFT ||
            $this->getStagingStorageOutput() === AbstractStrategyFactory::WORKSPACE_REDSHIFT;
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
