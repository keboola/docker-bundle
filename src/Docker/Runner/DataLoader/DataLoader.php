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
use Keboola\InputMapping\Staging\InputMappingStagingDefinition;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Options\InputTableOptionsList;
use Keboola\InputMapping\Table\Options\ReaderOptions;
use Keboola\InputMapping\Table\Result as InputTableResult;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\OutputMapping\Writer\AbstractWriter;
use Keboola\OutputMapping\Writer\FileWriter;
use Keboola\OutputMapping\Writer\TableWriter;
use Keboola\StagingProvider\InputProviderInitializer;
use Keboola\StagingProvider\OutputProviderInitializer;
use Keboola\StagingProvider\Provider\WorkspaceStagingProvider;
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
    private const NATIVE_TYPES_FEATURE = 'native-types';

    private ClientWrapper $clientWrapper;
    private LoggerInterface $logger;
    private string $dataDirectory;
    private array $storageConfig;
    private array $runtimeConfig;
    private string $defaultBucketName;
    private Component $component;
    private ?string $configId;
    private ?string $configRowId;
    private OutputFilterInterface $outputFilter;
    private InputStrategyFactory $inputStrategyFactory;
    private OutputStrategyFactory $outputStrategyFactory;
    private array $projectFeatures;

    /**
     * DataLoader constructor.
     *
     * @param ClientWrapper $clientWrapper
     * @param LoggerInterface $logger
     * @param string $dataDirectory
     * @param JobDefinition $jobDefinition
     * @param OutputFilterInterface $outputFilter
     */
    public function __construct(
        ClientWrapper $clientWrapper,
        LoggerInterface $logger,
        $dataDirectory,
        JobDefinition $jobDefinition,
        OutputFilterInterface $outputFilter,
    ) {
        $this->clientWrapper = $clientWrapper;
        $this->logger = $logger;
        $this->dataDirectory = $dataDirectory;
        $configuration = $jobDefinition->getConfiguration();
        $this->storageConfig = $configuration['storage'] ?? [];
        $this->runtimeConfig = $configuration['runtime'] ?? [];
        $this->component = $jobDefinition->getComponent();
        $this->outputFilter = $outputFilter;
        $this->configId = $jobDefinition->getConfigId();
        $this->configRowId = $jobDefinition->getRowId();
        $this->defaultBucketName = (string) ($this->storageConfig['output']['default_bucket'] ?? '');
        if ($this->defaultBucketName === '') {
            $this->defaultBucketName = $this->getDefaultBucket();
        }
        $this->validateStagingSetting();

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
        $this->projectFeatures = $tokenInfo['owner']['features'];

        /* dataDirectory is "something/data" - this https://github.com/keboola/docker-bundle/blob/f9d4cf0d0225097ba4e5a1952812c405e333ce72/src/Docker/Runner/WorkingDirectory.php#L90
            we need the base dir here */
        $dataDirectory = dirname($this->dataDirectory);

        $workspaceProviderFactoryFactory = new WorkspaceProviderFactoryFactory(
            new Components($this->clientWrapper->getBranchClient()),
            new Workspaces($this->clientWrapper->getBranchClient()),
            $this->logger,
        );
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        $workspaceProviderFactory = $workspaceProviderFactoryFactory->getWorkspaceProviderFactory(
            $this->getStagingStorageInput(),
            $this->component,
            $this->configId,
            $this->runtimeConfig['backend'] ?? [],
            $this->storageConfig['input']['read_only_storage_access'] ?? null,
        );
        $inputProviderInitializer = new InputProviderInitializer(
            $this->inputStrategyFactory,
            $workspaceProviderFactory,
            $dataDirectory,
        );
        $inputProviderInitializer->initializeProviders(
            $this->getStagingStorageInput(),
            $tokenInfo,
        );

        $outputProviderInitializer = new OutputProviderInitializer(
            $this->outputStrategyFactory,
            $workspaceProviderFactory,
            $dataDirectory,
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
                Redshift workspaces are reusable, but still cleaned up before each run (preserve = false). Other
                workspaces (Snowflake, Local) are ephemeral (thus the preserver flag is irrelevant for them).
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

    public function storeOutput($isFailedJob = false): ?LoadTableQueue
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
            AbstractWriter::SYSTEM_KEY_COMPONENT_ID => $this->component->getId(),
            AbstractWriter::SYSTEM_KEY_CONFIGURATION_ID => $this->configId,
        ];
        if ($this->configRowId) {
            $commonSystemMetadata[AbstractWriter::SYSTEM_KEY_CONFIGURATION_ROW_ID] = $this->configRowId;
        }
        $tableSystemMetadata = $fileSystemMetadata = $commonSystemMetadata;
        if ($this->clientWrapper->isDevelopmentBranch()) {
            $tableSystemMetadata[AbstractWriter::SYSTEM_KEY_BRANCH_ID] = $this->clientWrapper->getBranchId();
        }

        $fileSystemMetadata[AbstractWriter::SYSTEM_KEY_RUN_ID] = $this->clientWrapper->getBranchClient()->getRunId();

        // Get default bucket
        if ($this->defaultBucketName) {
            $uploadTablesOptions['bucket'] = $this->defaultBucketName;
            $this->logger->debug('Default bucket ' . $uploadTablesOptions['bucket']);
        }

        // Check whether we are creating typed tables
        $createTypedTables = in_array(self::NATIVE_TYPES_FEATURE, $this->projectFeatures, true);

        try {
            $fileWriter = new FileWriter($this->outputStrategyFactory);
            $fileWriter->setFormat($this->component->getConfigurationFormat());
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
            $tableWriter = new TableWriter($this->outputStrategyFactory);
            $tableWriter->setFormat($this->component->getConfigurationFormat());
            $tableQueue = $tableWriter->uploadTables(
                'data/out/tables/',
                $uploadTablesOptions,
                $tableSystemMetadata,
                $this->getStagingStorageOutput(),
                $createTypedTables,
                $isFailedJob,
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
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
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
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
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
     * @param $fileName
     * @param array $tags
     */
    public function storeDataArchive($fileName, array $tags)
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
            AbstractStrategyFactory::WORKSPACE_SNOWFLAKE, AbstractStrategyFactory::WORKSPACE_SYNAPSE];
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
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
                    continue;
                }
                if (in_array($stagingProvider, $cleanedProviders, true)) {
                    continue;
                }
                // don't clean ABS workspaces or Redshift workspaces which are reusable if created for a config
                if ($this->configId && $this->isReusableWorkspace()) {
                    continue;
                }

                try {
                    $stagingProvider->cleanup();
                    $cleanedProviders[] = $stagingProvider;
                } catch (ClientException $e) {
                    // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
                    $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage());
                }
            }
        }
    }

    private function isReusableWorkspace(): bool
    {
        return $this->getStagingStorageInput() === AbstractStrategyFactory::WORKSPACE_ABS ||
            $this->getStagingStorageOutput() === AbstractStrategyFactory::WORKSPACE_ABS ||
            $this->getStagingStorageInput() === AbstractStrategyFactory::WORKSPACE_REDSHIFT ||
            $this->getStagingStorageOutput() === AbstractStrategyFactory::WORKSPACE_REDSHIFT;
    }
}
