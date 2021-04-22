<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader;
use Keboola\InputMapping\Staging\Definition;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Options\InputTableOptionsList;
use Keboola\InputMapping\Table\Options\ReaderOptions;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\OutputMapping\Writer\FileWriter;
use Keboola\OutputMapping\Writer\TableWriter;
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
use Keboola\StagingProvider\WorkspaceProviderFactory\ComponentWorkspaceProviderFactory;
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
            we need need the base dir here */
        $dataDirectory = dirname($this->dataDirectory);

        $workspaceProviderFactory = new ComponentWorkspaceProviderFactory(
            new Components($this->clientWrapper->getBasicClient()),
            new Workspaces($this->clientWrapper->getBasicClient()),
            $this->component->getId(),
            $this->configId
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
     * @return InputTableStateList
     * @throws Exception
     */
    public function loadInputData(InputTableStateList $inputTableStateList)
    {
        $reader = new Reader($this->inputStrategyFactory);
        $resultInputTablesStateList = new InputTableStateList([]);
        $readerOptions = new ReaderOptions(!$this->component->allowBranchMapping());

        try {
            if (isset($this->storageConfig['input']['tables']) && count($this->storageConfig['input']['tables'])) {
                $this->logger->debug('Downloading source tables.');
                $resultInputTablesStateList = $reader->downloadTables(
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
                $reader->downloadFiles(
                    $this->storageConfig['input']['files'],
                    'data/in/files/',
                    $this->getStagingStorageInput()
                );
            }
        } catch (ClientException $e) {
            throw new UserException('Cannot import data from Storage API: ' . $e->getMessage(), $e);
        } catch (InvalidInputException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        return $resultInputTablesStateList;
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

        $this->logger->debug("Uploading output tables and files.");

        $uploadTablesOptions = ["mapping" => $outputTablesConfig];

        $systemMetadata = [
            TableWriter::SYSTEM_KEY_COMPONENT_ID => $this->component->getId(),
            TableWriter::SYSTEM_KEY_CONFIGURATION_ID => $this->configId,
        ];
        if ($this->configRowId) {
            $systemMetadata[TableWriter::SYSTEM_KEY_CONFIGURATION_ROW_ID] = $this->configRowId;
        }
        if ($this->clientWrapper->hasBranch()) {
            $systemMetadata[TableWriter::SYSTEM_KEY_BRANCH_ID] = $this->clientWrapper->getBranchId();
        }
        if ($this->useFileStorageOnly()) {
            $systemMetadata[TableWriter::SYSTEM_KEY_RUN_ID] = $this->clientWrapper->getBasicClient()->getRunId();
        }

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
                $this->useFileStorageOnly() ? $systemMetadata : [],
                $this->getStagingStorageOutput()
            );
            if ($this->useFileStorageOnly()) {
                $tablesFilesConfig = [];
                foreach ($outputTablesConfig as $table) {
                    $tablesFilesConfig[] = [
                        'source' => $table['source'],
                        'is_permanent' => true,
                        'tags' => $table['file_tags'],
                    ];
                }
                $fileWriter->uploadFiles(
                    'data/out/tables/',
                    ['mapping' => $tablesFilesConfig],
                    $systemMetadata,
                    $this->getStagingStorageOutput()
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
                $systemMetadata,
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
        // working only with inputStrategyFactory, but the workspaceproviders are shared between input and output, so it's "ok"
        foreach ($this->inputStrategyFactory->getStrategyMap() as $stagingDefinition) {
            foreach ($this->getStagingProviders($stagingDefinition) as $stagingProvider) {
                if (!$stagingProvider instanceof WorkspaceStagingProvider) {
                    continue;
                }

                try {
                    $stagingProvider->cleanup();
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
