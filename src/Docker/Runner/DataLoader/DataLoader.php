<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\Staging\Definition;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Options\InputTableOptionsList;
use Keboola\InputMapping\Table\Options\ReaderOptions;
use Keboola\InputMapping\Table\Result as InputTableResult;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\OutputMapping\Writer\FileWriter;
use Keboola\OutputMapping\Writer\TableWriter;
use Keboola\StagingProvider\Staging\Workspace\AbsWorkspaceStaging;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StagingProvider\InputProviderInitializer;
use Keboola\StagingProvider\OutputProviderInitializer;
use Keboola\StagingProvider\Provider\AbstractStagingProvider;
use Keboola\StagingProvider\Provider\WorkspaceStagingProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;

class DataLoader implements DataLoaderInterface
{
    /**
     * @var ClientWrapper
     */
    private $clientWrapper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var array
     */
    private $storageConfig;

    /**
     * @var array
     */
    private $runtimeConfig;

    /**
     * @var string
     */
    private $defaultBucketName;

    /**
     * @var Component
     */
    private $component;

    /**
     * @var string
     */
    private $configId;

    /**
     * @var string
     */
    private $configRowId;

    /** @var OutputFilterInterface */
    private $outputFilter;

    /** @var InputStrategyFactory */
    private $inputStrategyFactory;

    /** @var OutputStrategyFactory */
    private $outputStrategyFactory;

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
        OutputFilterInterface $outputFilter
    ) {
        $this->clientWrapper = $clientWrapper;
        $this->logger = $logger;
        $this->dataDirectory = $dataDirectory;
        $configuration = $jobDefinition->getConfiguration();
        $this->storageConfig = isset($configuration['storage']) ? $configuration['storage'] : [];
        $this->runtimeConfig = isset($configuration['runtime']) ? $configuration['runtime'] : [];
        $this->component = $jobDefinition->getComponent();
        $this->outputFilter = $outputFilter;
        $this->configId = $jobDefinition->getConfigId();
        $this->configRowId = $jobDefinition->getRowId();
        $this->defaultBucketName = $this->getDefaultBucket();
        $this->validateStagingSetting();

        $this->inputStrategyFactory = new InputStrategyFactory(
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat()
        );

        $this->outputStrategyFactory = new OutputStrategyFactory(
            $this->clientWrapper,
            $this->logger,
            $this->component->getConfigurationFormat()
        );

        $tokenInfo = $this->clientWrapper->getBasicClient()->verifyToken();

        /* dataDirectory is "something/data" - this https://github.com/keboola/docker-bundle/blob/f9d4cf0d0225097ba4e5a1952812c405e333ce72/src/Docker/Runner/WorkingDirectory.php#L90
            we need the base dir here */
        $dataDirectory = dirname($this->dataDirectory);

        $workspaceProviderFactoryFactory = new WorkspaceProviderFactoryFactory(
            new Components($this->clientWrapper->getBranchClientIfAvailable()),
            new Workspaces($this->clientWrapper->getBranchClientIfAvailable()),
            $this->logger
        );
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        $workspaceProviderFactory = $workspaceProviderFactoryFactory->getWorkspaceProviderFactory(
            $this->getStagingStorageInput(),
            $this->component,
            $this->configId,
            isset($this->runtimeConfig['backend']) ? $this->runtimeConfig['backend'] : []
        );
        $inputProviderInitializer = new InputProviderInitializer(
            $this->inputStrategyFactory,
            $workspaceProviderFactory,
            $dataDirectory
        );
        $inputProviderInitializer->initializeProviders(
            $this->getStagingStorageInput(),
            $tokenInfo
        );

        $outputProviderInitializer = new OutputProviderInitializer(
            $this->outputStrategyFactory,
            $workspaceProviderFactory,
            $dataDirectory
        );
        $outputProviderInitializer->initializeProviders(
            $this->getStagingStorageOutput(),
            $tokenInfo
        );
    }

    /**
     * Download source files
     * @param InputTableStateList $inputTableStateList
     * @param InputFileStateList $inputFileStateList
     * @return StorageState
     * @throws Exception
     */
    public function loadInputData(InputTableStateList $inputTableStateList, InputFileStateList $inputFileStateList)
    {
        $reader = new Reader($this->inputStrategyFactory);
        $inputTableResult = new InputTableResult();
        $inputTableResult->setInputTableStateList(new InputTableStateList([]));
        $resultInputFilesStateList = new InputFileStateList([]);

        $readerOptions = new ReaderOptions(!$this->component->allowBranchMapping(), false);
        try {
            if (isset($this->storageConfig['input']['tables']) && count($this->storageConfig['input']['tables'])) {
                $this->logger->debug('Downloading source tables.');
                $inputTableResult = $reader->downloadTables(
                    new InputTableOptionsList($this->storageConfig['input']['tables']),
                    $inputTableStateList,
                    'data/in/tables/',
                    $this->getStagingStorageInput(),
                    $readerOptions
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
                    $inputFileStateList
                );
            }
        } catch (ClientException $e) {
            throw new UserException('Cannot import data from Storage API: ' . $e->getMessage(), $e);
        } catch (InvalidInputException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        return new StorageState($inputTableResult, $resultInputFilesStateList);
    }

    /**
     * @return LoadTableQueue|null
     * @throws \Exception
     */
    public function storeOutput()
    {
        $this->logger->debug("Storing results.");
        $outputTablesConfig = [];
        $outputFilesConfig = [];
        $outputTableFilesConfig = [];

        if (isset($this->storageConfig["output"]["tables"]) &&
            count($this->storageConfig["output"]["tables"])
        ) {
            $outputTablesConfig = $this->storageConfig["output"]["tables"];
        }
        if (isset($this->storageConfig["output"]["files"]) &&
            count($this->storageConfig["output"]["files"])
        ) {
            $outputFilesConfig = $this->storageConfig["output"]["files"];
        }
        if (isset($this->storageConfig["output"]["table_files"]) &&
            count($this->storageConfig["output"]["table_files"])
        ) {
            $outputTableFilesConfig = $this->storageConfig["output"]["table_files"];
        }
        $this->logger->debug("Uploading output tables and files.");

        $uploadTablesOptions = ["mapping" => $outputTablesConfig];

        $commonSystemMetadata = [
            TableWriter::SYSTEM_KEY_COMPONENT_ID => $this->component->getId(),
            TableWriter::SYSTEM_KEY_CONFIGURATION_ID => $this->configId,
        ];
        if ($this->configRowId) {
            $commonSystemMetadata[TableWriter::SYSTEM_KEY_CONFIGURATION_ROW_ID] = $this->configRowId;
        }
        $tableSystemMetadata = $fileSystemMetadata = $commonSystemMetadata;
        if ($this->clientWrapper->hasBranch()) {
            $tableSystemMetadata[TableWriter::SYSTEM_KEY_BRANCH_ID] = $this->clientWrapper->getBranchId();
        }

        $fileSystemMetadata[TableWriter::SYSTEM_KEY_RUN_ID] = $this->clientWrapper->getBasicClient()->getRunId();

        // Get default bucket
        if ($this->defaultBucketName) {
            $uploadTablesOptions["bucket"] = $this->defaultBucketName;
            $this->logger->debug("Default bucket " . $uploadTablesOptions["bucket"]);
        }

        try {
            $fileWriter = new FileWriter($this->outputStrategyFactory);
            $fileWriter->setFormat($this->component->getConfigurationFormat());
            $fileWriter->uploadFiles(
                'data/out/files/',
                ['mapping' => $outputFilesConfig],
                $fileSystemMetadata,
                $this->getStagingStorageOutput()
            );
            if ($this->useFileStorageOnly()) {
                $fileWriter->uploadFiles(
                    'data/out/tables/',
                    [],
                    $fileSystemMetadata,
                    $this->getStagingStorageOutput(),
                    $outputTableFilesConfig
                );
                if (isset($this->storageConfig["input"]["files"])) {
                    // tag input files
                    $fileWriter->tagFiles($this->storageConfig["input"]["files"]);
                }
                return null;
            }
            $tableWriter = new TableWriter($this->outputStrategyFactory);
            $tableWriter->setFormat($this->component->getConfigurationFormat());
            $tableQueue = $tableWriter->uploadTables(
                'data/out/tables/',
                $uploadTablesOptions,
                $tableSystemMetadata,
                $this->getStagingStorageOutput()
            );
            if (isset($this->storageConfig["input"]["files"])) {
                // tag input files
                $fileWriter->tagFiles($this->storageConfig["input"]["files"]);
            }
            return $tableQueue;
        } catch (InvalidOutputException $ex) {
            throw new UserException($ex->getMessage(), $ex);
        }
    }

    private function useFileStorageOnly()
    {
        return $this->component->allowUseFileStorageOnly() && isset($this->runtimeConfig['use_file_storage_only']);
    }

    public function getWorkspaceCredentials()
    {
        // this returns the first workspace found, which is ok so far because there can only be one
        // (ensured in validateStagingSetting())
        // working only with inputStrategyFactory, but the workspaceproviders are shared between input and output, so it's "ok"
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
     * @return iterable<AbstractStagingProvider>
     */
    private function getStagingProviders(Definition $stagingDefinition)
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
                    throw new ApplicationException("Failed to add directory: " . $item->getFilename());
                }
            } else {
                if ($item->getPathname() == $zipFileName) {
                    continue;
                }
                if (($item->getRelativePathname() == 'config.json') || ($item->getRelativePathname() == 'state.json')) {
                    $configData = file_get_contents($item->getPathname());
                    $configData = $this->outputFilter->filter($configData);
                    if (!$zip->addFromString($item->getRelativePathname(), $configData)) {
                        throw new ApplicationException("Failed to add file: " . $item->getFilename());
                    }
                } elseif (!$zip->addFile($item->getPathname(), $item->getRelativePathname())) {
                    throw new ApplicationException("Failed to add file: " . $item->getFilename());
                }
            }
        }
        $zip->close();
        $uploadOptions = new FileUploadOptions();
        $uploadOptions->setTags($tags);
        $uploadOptions->setIsPermanent(false);
        $uploadOptions->setIsPublic(false);
        $uploadOptions->setNotify(false);
        $this->clientWrapper->getBasicClient()->uploadFile($zipFileName, $uploadOptions);
        $fs = new Filesystem();
        $fs->remove($zipFileName);
    }

    protected function getDefaultBucket()
    {
        if ($this->component->hasDefaultBucket()) {
            if (!$this->configId) {
                throw new UserException("Configuration ID not set, but is required for default_bucket option.");
            }
            return $this->component->getDefaultBucketName($this->configId);
        } else {
            return '';
        }
    }

    private function getStagingStorageInput()
    {
        if (($stagingStorage = $this->component->getStagingStorage()) !== null) {
            if (isset($stagingStorage['input'])) {
                return $stagingStorage['input'];
            }
        }
        return OutputStrategyFactory::LOCAL;
    }

    private function getStagingStorageOutput()
    {
        if (($stagingStorage = $this->component->getStagingStorage()) !== null) {
            if (isset($stagingStorage['output'])) {
                return $stagingStorage['output'];
            }
        }
        return OutputStrategyFactory::LOCAL;
    }

    private function validateStagingSetting()
    {
        $workspaceTypes = [OutputStrategyFactory::WORKSPACE_ABS, OutputStrategyFactory::WORKSPACE_REDSHIFT,
            OutputStrategyFactory::WORKSPACE_SNOWFLAKE, OutputStrategyFactory::WORKSPACE_SYNAPSE];
        if (in_array($this->getStagingStorageInput(), $workspaceTypes)
            && in_array($this->getStagingStorageOutput(), $workspaceTypes)
            && $this->getStagingStorageInput() !== $this->getStagingStorageOutput()
        ) {
            throw new ApplicationException(sprintf(
                'Component staging setting mismatch - input: "%s", output: "%s".',
                $this->getStagingStorageInput(),
                $this->getStagingStorageOutput()
            ));
        }
    }

    public function cleanWorkspace()
    {
        $cleanedProviders = [];
        $maps = array_merge(
            $this->inputStrategyFactory->getStrategyMap(),
            $this->outputStrategyFactory->getStrategyMap()
        );
        foreach ($maps as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
                    continue;
                }
                if (in_array($stagingProvider, $cleanedProviders, true)) {
                    continue;
                }
                // don't clean ABS workspace which is persistent if created for a config
                if ($this->configId && ($stagingProvider->getStaging()->getType() === AbsWorkspaceStaging::getType())) {
                    continue;
                }

                try {
                    $stagingProvider->cleanup();
                    $cleanedProviders[] = $stagingProvider;
                } catch (ClientException $e) {
                    // ignore errors if the cleanup fails because the workspace is already gone
                    if ($e->getStringCode() !== 'workspace.workspaceNotFound') {
                        throw $e;
                    }
                }
            }
        }
    }
}
