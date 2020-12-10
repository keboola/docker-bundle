<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader\NullWorkspaceProvider;
use Keboola\InputMapping\Reader\Options\InputTableOptionsList;
use Keboola\InputMapping\Reader\Reader;
use Keboola\InputMapping\Reader\State\InputTableStateList;
use Keboola\InputMapping\Reader\WorkspaceProviderInterface;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Writer\FileWriter;
use Keboola\OutputMapping\Writer\TableWriter;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApiBranch\ClientWrapper;
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

    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;
    /**
     * @var WorkspaceProvider
     */
    private $workspaceProvider;

    /**
     * DataLoader constructor.
     *
     * @param ClientWrapper $clientWrapper
     * @param LoggerInterface $logger
     * @param string $dataDirectory
     * @param array $storageConfig
     * @param Component $component
     * @param OutputFilterInterface $outputFilter
     * @param string|null $configId
     * @param string|null $configRowId
     */
    public function __construct(
        ClientWrapper $clientWrapper,
        LoggerInterface $logger,
        $dataDirectory,
        array $storageConfig,
        Component $component,
        OutputFilterInterface $outputFilter,
        $configId = null,
        $configRowId = null
    ) {
        $this->clientWrapper = $clientWrapper;
        $this->logger = $logger;
        $this->dataDirectory = $dataDirectory;
        $this->storageConfig = $storageConfig;
        $this->component = $component;
        $this->outputFilter = $outputFilter;
        $this->configId = $configId;
        $this->configRowId = $configRowId;
        $this->defaultBucketName = $this->getDefaultBucket();
        $this->validateStagingSetting();
        /* this condition is here so as not to create a workspace when not needed for the job at all
            checking only output setting is ok because input and output workspace mapping must match -
            see  validateStagingSetting() */
        if (($this->getStagingStorageOutput() === Reader::STAGING_SNOWFLAKE) ||
            ($this->getStagingStorageOutput() === Reader::STAGING_REDSHIFT) ||
            ($this->getStagingStorageOutput() === Reader::STAGING_SYNAPSE) ||
            ($this->getStagingStorageOutput() === Reader::STAGING_ABS_WORKSPACE)
        ) {
            $this->workspaceProvider = new WorkspaceProvider(
                $this->clientWrapper->getBasicClient(),
                $this->component->getId(),
                $this->configId
            );
        } else {
            $this->workspaceProvider = new NullWorkspaceProvider();
        }
    }

    /**
     * Download source files
     * @param InputTableStateList $inputTableStateList
     * @return InputTableStateList
     * @throws \Keboola\StorageApi\Exception
     */
    public function loadInputData(InputTableStateList $inputTableStateList)
    {
        $reader = new Reader($this->clientWrapper, $this->logger, $this->workspaceProvider);
        $reader->setFormat($this->component->getConfigurationFormat());

        $resultInputTablesStateList = new InputTableStateList([]);

        try {
            if (isset($this->storageConfig['input']['tables']) && count($this->storageConfig['input']['tables'])) {
                $this->logger->debug('Downloading source tables.');
                $resultInputTablesStateList = $reader->downloadTables(
                    new InputTableOptionsList($this->storageConfig['input']['tables']),
                    $inputTableStateList,
                    $this->dataDirectory . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'tables',
                    $this->getStagingStorageInput()
                );
            }
            if (isset($this->storageConfig['input']['files']) &&
                count($this->storageConfig['input']['files'])
            ) {
                $this->logger->debug('Downloading source files.');
                $reader->downloadFiles(
                    $this->storageConfig['input']['files'],
                    $this->dataDirectory . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'files',
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
            'componentId' => $this->component->getId(),
            'configurationId' => $this->configId,
        ];
        if ($this->configRowId) {
            $systemMetadata['configurationRowId'] = $this->configRowId;
        }
        if ($this->clientWrapper->hasBranch()) {
            $systemMetadata['branchId'] = $this->clientWrapper->getBranchId();
        }

        // Get default bucket
        if ($this->defaultBucketName) {
            $uploadTablesOptions["bucket"] = $this->defaultBucketName;
            $this->logger->debug("Default bucket " . $uploadTablesOptions["bucket"]);
        }

        try {
            $fileWriter = new FileWriter($this->clientWrapper, $this->logger);
            $fileWriter->setFormat($this->component->getConfigurationFormat());
            $fileWriter->uploadFiles($this->dataDirectory . "/out/files", ["mapping" => $outputFilesConfig]);
            $tableWriter = new TableWriter($this->clientWrapper, $this->logger, $this->workspaceProvider);
            $tableWriter->setFormat($this->component->getConfigurationFormat());
            $tableQueue = $tableWriter->uploadTables(
                $this->dataDirectory . '/out/tables',
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

    public function getWorkspaceCredentials()
    {
        if (array_key_exists($this->getStagingStorageInput(), WorkspaceProvider::STAGING_TYPE_MAP)) {
            return $this->workspaceProvider->getCredentials(
                WorkspaceProvider::STAGING_TYPE_MAP[$this->getStagingStorageInput()]
            );
        } else {
            return [];
        }
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
        return Reader::STAGING_LOCAL;
    }

    private function getStagingStorageOutput()
    {
        if (($stagingStorage = $this->component->getStagingStorage()) !== null) {
            if (isset($stagingStorage['output'])) {
                return $stagingStorage['output'];
            }
        }
        return Reader::STAGING_LOCAL;
    }

    private function validateStagingSetting()
    {
        if (array_key_exists($this->getStagingStorageInput(), WorkspaceProvider::STAGING_TYPE_MAP)
            && array_key_exists($this->getStagingStorageOutput(), WorkspaceProvider::STAGING_TYPE_MAP)
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
        $this->workspaceProvider->cleanup();
    }
}
