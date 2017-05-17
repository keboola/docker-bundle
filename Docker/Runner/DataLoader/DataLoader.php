<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\InputMapping\Exception\InvalidInputException;
use Keboola\InputMapping\Reader\Reader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class DataLoader
{
    /**
     * @var Client
     */
    private $storageClient;

    /**
     * @var Logger
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
     * DataLoader constructor.
     *
     * @param Client $storageClient
     * @param Logger $logger
     * @param string $dataDirectory
     * @param array $storageConfig
     * @param Component $component
     * @param string|null $configId
     */
    public function __construct(
        Client $storageClient,
        Logger $logger,
        $dataDirectory,
        array $storageConfig,
        Component $component,
        $configId = null
    ) {
        $this->storageClient = $storageClient;
        $this->logger = $logger;
        $this->dataDirectory = $dataDirectory;
        $this->storageConfig = $storageConfig;
        $this->component = $component;
        $this->configId = $configId;
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

        $writer->setFormat($this->component->getConfigurationFormat());

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

        // Get default bucket
        if ($this->defaultBucketName) {
            $uploadTablesOptions["bucket"] = $this->defaultBucketName;
            $this->logger->debug("Default bucket " . $uploadTablesOptions["bucket"]);
        }

        $writer->uploadTables($this->dataDirectory . "/out/tables", $uploadTablesOptions, $systemMetadata);
        try {
            $writer->uploadFiles($this->dataDirectory . "/out/files", ["mapping" => $outputFilesConfig]);
        } catch (ManifestMismatchException $e) {
            $this->logger->warn($e->getMessage());
        }

        if (isset($this->storageConfig["input"]["files"])) {
            // tag input files
            $writer->tagFiles($this->storageConfig["input"]["files"]);
        }
    }

    /**
     * Archive data directory and save it to Storage, do not actually run the container.
     * @param array $tags Arbitrary storage tags
     */
    public function storeDataArchive(array $tags)
    {
        $zip = new \ZipArchive();
        $zipFileName = 'data.zip';
        $zipDir = $this->dataDirectory . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'zip';
        $fs = new Filesystem();
        $fs->mkdir($zipDir);
        $zip->open($zipDir. DIRECTORY_SEPARATOR . $zipFileName, \ZipArchive::CREATE);
        $finder = new Finder();
        /** @var SplFileInfo $item */
        foreach ($finder->in($this->dataDirectory) as $item) {
            if ($item->getPathname() == $zipDir) {
                continue;
            }
            if ($item->isDir()) {
                if (!$zip->addEmptyDir($item->getRelativePathname())) {
                    throw new ApplicationException("Failed to add directory: ".$item->getFilename());
                }
            } else {
                if (!$zip->addFile($item->getPathname(), $item->getRelativePathname())) {
                    throw new ApplicationException("Failed to add file: ".$item->getFilename());
                }
            }
        }
        $zip->close();

        $writer = new Writer($this->storageClient, $this->logger);
        $writer->setFormat($this->component->getConfigurationFormat());
        // zip archive must be created in special directory, because uploadFiles is recursive
        $writer->uploadFiles(
            $zipDir,
            ["mapping" =>
                [
                    [
                        'source' => $zipFileName,
                        'tags' => $tags,
                        'is_permanent' => false,
                        'is_encrypted' => true,
                        'is_public' => false,
                        'notify' => false
                    ]
                ]
            ]
        );
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
