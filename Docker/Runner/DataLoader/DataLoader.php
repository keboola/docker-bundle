<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader\Reader;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Writer\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class DataLoader implements DataLoaderInterface
{
    /**
     * @var Client
     */
    private $storageClient;

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
     * @var array
     */
    protected $features = [];

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
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    /**
     * DataLoader constructor.
     *
     * @param Client $storageClient
     * @param LoggerInterface $logger
     * @param string $dataDirectory
     * @param array $storageConfig
     * @param Component $component
     * @param ObjectEncryptorFactory $encryptorFactory
     * @param string|null $configId
     * @param string|null $configRowId
     */
    public function __construct(
        Client $storageClient,
        LoggerInterface $logger,
        $dataDirectory,
        array $storageConfig,
        Component $component,
        ObjectEncryptorFactory $encryptorFactory,
        $configId = null,
        $configRowId = null
    ) {
        $this->storageClient = $storageClient;
        $this->logger = $logger;
        $this->dataDirectory = $dataDirectory;
        $this->storageConfig = $storageConfig;
        $this->component = $component;
        $this->encryptorFactory = $encryptorFactory;
        $this->configId = $configId;
        $this->configRowId = $configRowId;
        $this->defaultBucketName = $this->getDefaultBucket();
    }

    /**
     * Download source files
     */
    public function loadInputData()
    {
        $reader = new Reader($this->storageClient, $this->logger);
        $reader->setFormat($this->component->getConfigurationFormat());

        try {
            if (isset($this->storageConfig['input']['tables']) && count($this->storageConfig['input']['tables'])) {
                $this->logger->debug('Downloading source tables.');
                $reader->downloadTables(
                    $this->storageConfig['input']['tables'],
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
                    $this->dataDirectory . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'files'
                );
            }
        } catch (ClientException $e) {
            throw new UserException('Cannot import data from Storage API: ' . $e->getMessage(), $e);
        } catch (InvalidInputException $e) {
            throw new UserException($e->getMessage(), $e);
        }
    }

    public function storeOutput()
    {
        $this->logger->debug("Storing results.");

        $writer = new Writer($this->storageClient, $this->logger);
        $writer->setFormat($this->component->getConfigurationFormat());
        $writer->setFeatures($this->features);

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
            'configurationId' => $this->configId
        ];
        if ($this->configRowId) {
            $systemMetadata['configurationRowId'] = $this->configRowId;
        }

        // Get default bucket
        if ($this->defaultBucketName) {
            $uploadTablesOptions["bucket"] = $this->defaultBucketName;
            $this->logger->debug("Default bucket " . $uploadTablesOptions["bucket"]);
        }

        try {
            $writer->uploadTables($this->dataDirectory . "/out/tables", $uploadTablesOptions, $systemMetadata);
            $writer->uploadFiles($this->dataDirectory . "/out/files", ["mapping" => $outputFilesConfig]);

            if (isset($this->storageConfig["input"]["files"])) {
                // tag input files
                $writer->tagFiles($this->storageConfig["input"]["files"]);
            }
        } catch (InvalidOutputException $ex) {
            throw new UserException($ex->getMessage(), $ex);
        }
    }

    /**
     * Archive data directory and save it to Storage
     * @param array $tags Arbitrary storage tags
     * @throws ClientException
     */
    public function storeDataArchive(array $tags)
    {
        $zip = new \ZipArchive();
        $zipFileName = 'data.zip';
        $zip->open($this->dataDirectory . DIRECTORY_SEPARATOR . $zipFileName, \ZipArchive::CREATE);
        $finder = new Finder();
        /** @var SplFileInfo $item */
        foreach ($finder->in($this->dataDirectory) as $item) {
            if ($item->isDir()) {
                if (!$zip->addEmptyDir($item->getRelativePathname())) {
                    throw new ApplicationException("Failed to add directory: ".$item->getFilename());
                }
            } else {
                if ($item->$item->getRelativePathname() == $zipFileName) {
                    continue;
                }
                if (($item->$item->getRelativePathname() == 'config.json') || ($item->getRelativePathname() == 'state.json')) {
                    $configData = \GuzzleHttp\json_decode(file_get_contents($item->getPathname()));
                    $configData = $this->encryptorFactory->getEncryptor()->encrypt($configData);
                    if (!$zip->addFromString($item->getPathname(), $configData)) {
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
        $this->storageClient->uploadFile($zipFileName, $uploadOptions);
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
        return 'local';
    }
}
