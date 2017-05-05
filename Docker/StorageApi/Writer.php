<?php

namespace Keboola\DockerBundle\Docker\StorageApi;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration\Output\File;
use Keboola\DockerBundle\Docker\Configuration\Output\Table;
use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Exception\MissingFileException;
use Keboola\InputMapping\Reader\Reader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\Temp\Temp;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Keboola\Syrup\Exception\UserException;

/**
 * Class Writer
 * @package Keboola\DockerBundle\Docker\StorageApi
 */
class Writer
{

    const SYSTEM_METADATA_PROVIDER = 'system';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Metadata
     */
    protected $metadataClient;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var
     */
    protected $format = 'json';

    /**
     * @var array
     */
    protected $features = [];

    /**
     * @return mixed
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param mixed $format
     * @return $this
     */
    public function setFormat($format)
    {
        $this->format = $format;

        return $this;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param Client $client
     * @return $this
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @param Metadata $metadataClient
     * @return $this
     */
    public function setMetadataClient(Metadata $metadataClient)
    {
        $this->metadataClient = $metadataClient;

        return $this;
    }

    /**
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param Logger $logger
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
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

    /**
     * @param $feature
     * @return bool
     */
    public function hasFeature($feature)
    {
        return in_array($feature, $this->features);
    }

    /**
     * Writer constructor.
     *
     * @param Client $client
     * @param Logger $logger
     */
    public function __construct(Client $client, Logger $logger)
    {
        $this->setClient($client);
        $this->setMetadataClient(new Metadata($client));
        $this->setLogger($logger);
    }

    /**
     * Upload files from local temp directory to Storage.
     *
     * @param string $source Source path.
     * @param array $configuration Upload configuration
     */
    public function uploadFiles($source, $configuration = [])
    {

        $manifestNames = $this->getManifestFiles($source);

        $finder = new Finder();
        /** @var SplFileInfo[] $files */
        $files = $finder->files()->notName("*.manifest")->in($source)->depth(0);

        $outputMappingFiles = array();
        if (isset($configuration["mapping"])) {
            foreach ($configuration["mapping"] as $mapping) {
                $outputMappingFiles[] = $mapping["source"];
            }
        }
        $outputMappingFiles = array_unique($outputMappingFiles);
        $processedOutputMappingFiles = array();

        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $file->getFilename();
        }

        // Check if all files from output mappings are present
        if (isset($configuration["mapping"])) {
            foreach ($configuration["mapping"] as $mapping) {
                if (!in_array($mapping["source"], $fileNames)) {
                    throw new MissingFileException("File '{$mapping["source"]}' not found.");
                }
            }
        }

        // Check for manifest orphans
        foreach ($manifestNames as $manifest) {
            if (!in_array(substr(basename($manifest), 0, -9), $fileNames)) {
                throw new ManifestMismatchException("Found orphaned file manifest: '" . basename($manifest) . "'");
            }
        }

        foreach ($files as $file) {
            $configFromMapping = array();
            $configFromManifest = array();
            if (isset($configuration["mapping"])) {
                foreach ($configuration["mapping"] as $mapping) {
                    if (isset($mapping["source"]) && $mapping["source"] == $file->getFilename()) {
                        $configFromMapping = $mapping;
                        $processedOutputMappingFiles[] = $configFromMapping["source"];
                        unset($configFromMapping["source"]);
                    }
                }
            }
            $manifestKey = array_search($file->getPathname() . ".manifest", $manifestNames);
            if ($manifestKey !== false) {
                $configFromManifest = $this->readFileManifest($file->getPathname() . ".manifest");
                unset($manifestNames[$manifestKey]);
            }
            try {
                // Mapping with higher priority
                if ($configFromMapping || !$configFromManifest) {
                    $storageConfig = (new File\Manifest())->parse(array($configFromMapping));
                } else {
                    $storageConfig = (new File\Manifest())->parse(array($configFromManifest));
                }
            } catch (InvalidConfigurationException $e) {
                throw new UserException("Failed to write manifest for table {$file->getFilename()}.", $e);
            }
            try {
                $this->uploadFile($file->getPathname(), $storageConfig);
            } catch (ClientException $e) {
                throw new UserException(
                    "Cannot upload file '{$file->getFilename()}' to Storage API: " . $e->getMessage(),
                    $e
                );
            }
        }

        $processedOutputMappingFiles = array_unique($processedOutputMappingFiles);
        $diff = array_diff(
            array_merge($outputMappingFiles, $processedOutputMappingFiles),
            $processedOutputMappingFiles
        );
        if (count($diff)) {
            throw new UserException("Couldn't process output mapping for file(s) '" . join("', '", $diff) . "'.");
        }
    }

    /**
     * @param $source
     * @return array
     */
    protected function readFileManifest($source)
    {
        $adapter = new File\Manifest\Adapter($this->getFormat());
        try {
            return $adapter->readFromFile($source);
        } catch (\Exception $e) {
            throw new ManifestMismatchException(
                "Failed to parse manifest file $source as " . $this->getFormat() . " " . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * @param $source
     * @param array $config
     * @throws \Keboola\StorageApi\ClientException
     */
    protected function uploadFile($source, $config = array())
    {
        $options = new FileUploadOptions();
        $options
            ->setTags(array_unique($config["tags"]))
            ->setIsPermanent($config["is_permanent"])
            ->setIsEncrypted($config["is_encrypted"])
            ->setIsPublic($config["is_public"])
            ->setNotify($config["notify"]);
        $this->getClient()->uploadFile($source, $options);
    }

    /**
     * @param $source
     * @param array $configuration
     */
    public function uploadTables($source, $configuration = [])
    {
        $manifestNames = $this->getManifestFiles($source);

        $finder = new Finder();

        /** @var SplFileInfo[] $files */
        $files = $finder->notName("*.manifest")->in($source)->depth(0);

        $outputMappingTables = array();
        if (isset($configuration["mapping"])) {
            foreach ($configuration["mapping"] as $mapping) {
                $outputMappingTables[] = $mapping["source"];
            }
        }
        $outputMappingTables = array_unique($outputMappingTables);
        $processedOutputMappingTables = array();

        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $file->getFilename();
        }

        // Check if all files from output mappings are present
        if (isset($configuration["mapping"])) {
            foreach ($configuration["mapping"] as $mapping) {
                if (!in_array($mapping["source"], $fileNames)) {
                    throw new MissingFileException("Table source '{$mapping["source"]}' not found.");
                }
            }
        }

        // Check for manifest orphans
        foreach ($manifestNames as $manifest) {
            if (!in_array(substr(basename($manifest), 0, -9), $fileNames)) {
                throw new ManifestMismatchException("Found orphaned table manifest: '" . basename($manifest) . "'");
            }
        }

        foreach ($files as $file) {
            $configFromMapping = array();
            $configFromManifest = array();
            if (isset($configuration["mapping"])) {
                foreach ($configuration["mapping"] as $mapping) {
                    if (isset($mapping["source"]) && $mapping["source"] == $file->getFilename()) {
                        $configFromMapping = $mapping;
                        $processedOutputMappingTables[] = $configFromMapping["source"];
                        unset($configFromMapping["source"]);
                    }
                }
            }

            $prefix = isset($configuration['bucket']) ? ($configuration['bucket'] . '.') : '';

            $manifestKey = array_search($file->getPathname() . ".manifest", $manifestNames);
            if ($manifestKey !== false) {
                $configFromManifest = $this->readTableManifest($file->getPathname() . ".manifest");
                if (empty($configFromManifest["destination"]) || isset($configuration['bucket'])) {
                    $configFromManifest['destination'] = $this->createDestinationConfigParam($prefix, $file->getFilename());
                }
                unset($manifestNames[$manifestKey]);
            } else {
                // If no manifest found and no output mapping, use filename (without .csv if present) as table id
                if (empty($configFromMapping["destination"]) || isset($configuration['bucket'])) {
                    $configFromMapping["destination"] = $this->createDestinationConfigParam($prefix, $file->getFilename());
                }
            }

            try {
                // Mapping with higher priority
                if ($configFromMapping || !$configFromManifest) {
                    $config = (new Table\Manifest())->parse(array($configFromMapping));
                } else {
                    $config = (new Table\Manifest())->parse(array($configFromManifest));
                }
            } catch (InvalidConfigurationException $e) {
                throw new UserException("Failed to write manifest for table {$file->getFilename()}.", $e);
            }

            if (count(explode(".", $config["destination"])) != 3) {
                throw new UserException(
                    "CSV file '{$config["destination"]}' file name is not a valid table identifier, " .
                    "either set output mapping for '{$file->getRelativePathname()}' or make sure " .
                    "that the file name is a valid Storage table identifier."
                );
            }

            try {
                /* TODO: because Redshift does not support both enclosure and escaped_by parameters, we cannot
                    support both, so we remove escaped_by, this should be removed from file manifest, but cannot
                    be done until all images are updated not to use the parameter.
                The following unset should be removed once the 'escaped_by' parameter is removed from table manifest. */
                unset($config['escaped_by']);
                $config["primary_key"] = self::normalizePrimaryKey($config["primary_key"]);
                if (isset($configuration['provider'])) {
                    $config['provider'] = $configuration['provider'];
                } else {
                    $config['provider'] = ["componentId" => "unknown", "configurationId" => ""];
                }

                $this->uploadTable($file->getPathname(), $config);
            } catch (ClientException $e) {
                throw new UserException(
                    "Cannot upload file '{$file->getFilename()}' to table '{$config["destination"]}' in Storage API: "
                    . $e->getMessage(),
                    $e
                );
            }

            // After the file has been written, we can write metadata
            if (isset($config['metadata']) && !empty($config['metadata'])) {
                $this->metadataClient->postTableMetadata($config["destination"], $configuration["provider"]['componentId'], $config["metadata"]);
            }
            if (isset($config['columnMetadata']) && !empty($config['columnMetadata'])) {
                $this->writeColumnMetadata($config["destination"], $configuration["provider"]['componentId'], $config["columnMetadata"]);
            }
        }

        $processedOutputMappingTables = array_unique($processedOutputMappingTables);
        $diff = array_diff(
            array_merge($outputMappingTables, $processedOutputMappingTables),
            $processedOutputMappingTables
        );
        if (count($diff)) {
            throw new UserException("Couldn't process output mapping for file(s) '" . join("', '", $diff) . "'.");
        }
    }

    /**
     * Creates destination configuration parameter from prefix and file name
     * @param $prefix
     * @param $filename
     * @return string
     */
    protected function createDestinationConfigParam($prefix, $filename)
    {
        if (substr($filename, -4) == '.csv') {
            return $prefix . substr($filename, 0, strlen($filename) - 4);
        } else {
            return $prefix . $filename;
        }
    }

    /**
     * @param $source
     * @return array
     * @throws \Exception
     */
    protected function readTableManifest($source)
    {
        $adapter = new Table\Manifest\Adapter($this->getFormat());
        try {
            return $adapter->readFromFile($source);
        } catch (InvalidConfigurationException $e) {
            throw new UserException(
                "Failed to read table manifest from file " . basename($source) . ' ' . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * @param $source
     * @param array $config
     * @throws \Keboola\StorageApi\ClientException
     */
    protected function uploadTable($source, $config = array())
    {
        $tableIdParts = explode(".", $config["destination"]);
        $bucketId = $tableIdParts[0] . "." . $tableIdParts[1];
        $bucketName = substr($tableIdParts[1], 2);
        $tableName = $tableIdParts[2];

        if (is_dir($source) && empty($config["columns"])) {
            throw new UserException("Sliced file '" . basename($source) . "': columns specification missing.");
        }

        $systemCreateMeta = array(
            [
                'key' => 'KBC.createdBy.component.id',
                'value' => $config['provider']['componentId']
            ],
            [
                'key' => 'KBC.createdBy.configuration.id',
                'value' => $config['provider']['configurationId']
            ]
        );

        // Create bucket if not exists
        if (!$this->client->bucketExists($bucketId)) {
            $this->client->createBucket($bucketName, $tableIdParts[0], "Created by Docker Runner");
            $this->metadataClient->postBucketMetadata($bucketId, self::SYSTEM_METADATA_PROVIDER, $systemCreateMeta, "bucket");
        }

        if ($this->client->tableExists($config["destination"])) {
            $tableInfo = $this->getClient()->getTable($config["destination"]);
            try {
                $this->validateAgainstTable($tableInfo, $config);
            } catch (UserException $e) {
                if ($this->hasFeature('docker-runner-output-mapping-strict-pk')) {
                    throw $e;
                }
                try {
                    $this->getLogger()->warn($e->getMessage());
                } catch (\Exception $eLog) {
                    // ignore
                }
            }

            if (self::modifyPrimaryKeyDecider($tableInfo, $config)) {
                $this->getLogger()->warn("Modifying primary key of table {$tableInfo["id"]} from [" . join(", ", $tableInfo["primaryKey"]) . "] to [" . join(", ", $config["primary_key"]) . "].");
                $failed = false;
                // modify primary key
                if (count($tableInfo["primaryKey"]) > 0) {
                    try {
                        $this->client->removeTablePrimaryKey($tableInfo["id"]);
                    } catch (\Exception $e) {
                        // warn and go on
                        $this->getLogger()->warn(
                            "Error deleting primary key of table {$tableInfo["id"]}: " . $e->getMessage()
                        );
                        $failed = true;
                    }
                }
                if (!$failed) {
                    try {
                        if (count($config["primary_key"])) {
                            $this->client->createTablePrimaryKey($tableInfo["id"], $config["primary_key"]);
                        }
                    } catch (\Exception $e) {
                        // warn and try to rollback to original state
                        $this->getLogger()->warn(
                            "Error changing primary key of table {$tableInfo["id"]}: " . $e->getMessage()
                        );
                        if (count($tableInfo["primaryKey"]) > 0) {
                            $this->client->createTablePrimaryKey($tableInfo["id"], $tableInfo["primaryKey"]);
                        }
                    }
                }
            }
            if (!empty($config["delete_where_column"])) {
                // Index columns
                $tableInfo = $this->getClient()->getTable($config["destination"]);
                if (!in_array($config["delete_where_column"], $tableInfo["indexedColumns"])) {
                    $this->getClient()->markTableColumnAsIndexed(
                        $config["destination"],
                        $config["delete_where_column"]
                    );
                }

                // Delete rows
                $deleteOptions = array(
                    "whereColumn" => $config["delete_where_column"],
                    "whereOperator" => $config["delete_where_operator"],
                    "whereValues" => $config["delete_where_values"]
                );
                $this->getClient()->deleteTableRows($config["destination"], $deleteOptions);
            }
            $options = array(
                "incremental" => $config["incremental"]
            );
            // headless csv file
            if (!empty($config["columns"])) {
                $options["columns"] = $config["columns"];
                $options["withoutHeaders"] = true;
            }
            if (is_dir($source)) {
                $options["delimiter"] = $config["delimiter"];
                $options["enclosure"] = $config["enclosure"];
                $this->writeSlicedTable($source, $config["destination"], $options);
            } else {
                $csvFile = new CsvFile($source, $config["delimiter"], $config["enclosure"]);
                $this->client->writeTableAsync($config["destination"], $csvFile, $options);
            }

            $systemUpdateMeta = array(
                [
                    'key' => 'KBC.lastUpdatedBy.component.id',
                    'value' => $config['provider']['componentId']
                ],
                [
                    'key' => 'KBC.lastUpdatedBy.configuration.id',
                    'value' => $config['provider']['configurationId']
                ]
            );
            $this->metadataClient->postTableMetadata($config['destination'], self::SYSTEM_METADATA_PROVIDER, $systemUpdateMeta);
        } else {
            $options = array(
                "primaryKey" => join(",", self::normalizePrimaryKey($config["primary_key"]))
            );
            $tableId = $config['destination'];
            // headless csv file
            if (!empty($config["columns"])) {
                $tmp = new Temp();
                $headerCsvFile = new CsvFile($tmp->createFile($tableName . '.header.csv'));
                $headerCsvFile->writeRow($config["columns"]);
                $this->client->createTableAsync($bucketId, $tableName, $headerCsvFile, $options);
                $options["columns"] = $config["columns"];
                $options["withoutHeaders"] = true;
                if (is_dir($source)) {
                    $options["delimiter"] = $config["delimiter"];
                    $options["enclosure"] = $config["enclosure"];
                    $this->writeSlicedTable($source, $config["destination"], $options);
                } else {
                    $csvFile = new CsvFile($source, $config["delimiter"], $config["enclosure"]);
                    $this->client->writeTableAsync($config["destination"], $csvFile, $options);
                }
            } else {
                $csvFile = new CsvFile($source, $config["delimiter"], $config["enclosure"]);
                $tableId = $this->client->createTableAsync($bucketId, $tableName, $csvFile, $options);
            }
            $this->metadataClient->postTableMetadata($tableId, self::SYSTEM_METADATA_PROVIDER, $systemCreateMeta);
        }
    }

    /**
     * @param $tableId
     * @param $provider
     * @param $columnMetadata
     * @throws ClientException
     */
    protected function writeColumnMetadata($tableId, $provider, $columnMetadata)
    {
        foreach ($columnMetadata as $column => $metadataArray) {
            $columnId = $tableId . "." . $column;
            $this->metadataClient->postColumnMetadata($columnId, $provider, $metadataArray);
        }
    }

    /**
     *
     * Uploads a sliced table to storage api. Takes all files from the $source folder
     *
     * @param string $source Slices folder
     * @param string $destination Destination table
     * @param array $options WriteTable options
     */
    protected function writeSlicedTable($source, $destination, $options)
    {
        $finder = new Finder();
        $slices = $finder->files()->in($source)->depth(0);
        $sliceFiles = [];
        foreach ($slices as $slice) {
            $sliceFiles[] = $slice->getPathname();
        }
        if (count($sliceFiles) === 0) {
            return;
        }

        // upload slices
        $fileUploadOptions = new FileUploadOptions();
        $fileUploadOptions
                ->setIsSliced(true)
                ->setFileName(basename($source))
        ;
        $uploadFileId = $this->client->uploadSlicedFile($sliceFiles, $fileUploadOptions);

        // write table
        $options["dataFileId"] = $uploadFileId;
        $this->client->writeTableAsyncDirect($destination, $options);
    }

    /**
     * @param $dir
     * @return array
     */
    protected function getManifestFiles($dir)
    {
        $finder = new Finder();
        $manifests = $finder->files()->name("*.manifest")->in($dir)->depth(0);
        $manifestNames = [];
        /** @var SplFileInfo $manifest */
        foreach ($manifests as $manifest) {
            $manifestNames[] = $manifest->getPathname();
        }
        return $manifestNames;
    }

    /**
     * Add tags to processed input files.
     * @param $configuration array
     */
    public function tagFiles(array $configuration)
    {
        $reader = new Reader($this->client, $this->logger);
        foreach ($configuration as $fileConfiguration) {
            if (!empty($fileConfiguration['processed_tags'])) {
                $files = $reader->getFiles($fileConfiguration);
                foreach ($files as $file) {
                    foreach ($fileConfiguration['processed_tags'] as $tag) {
                        $this->getClient()->addFileTag($file["id"], $tag);
                    }
                }
            }
        }
    }

    /**
     * @param array $tableInfo
     * @param array $config
     */
    public function validateAgainstTable($tableInfo = [], $config = [])
    {
        // primary key
        $configPK = self::normalizePrimaryKey($config["primary_key"]);
        if (count($configPK) > 0 || count($tableInfo["primaryKey"]) > 0) {
            if (count(array_diff($tableInfo["primaryKey"], $configPK)) > 0 ||
                count(array_diff($configPK, $tableInfo["primaryKey"])) > 0
            ) {
                $pkMapping = join(", ", $configPK);
                $pkTable = join(", ", $tableInfo["primaryKey"]);
                $message = "Output mapping does not match destination table: primary key '{$pkMapping}' does not match '{$pkTable}' in '{$config["destination"]}'.";
                throw new UserException($message);
            }
        }
    }

    /**
     * @param array $pKey
     * @return array
     */
    public static function normalizePrimaryKey(array $pKey)
    {
        return array_map(
            function ($pKey) {
                return trim($pKey);
            },
            array_unique(
                array_filter($pKey, function ($col) {
                    if ($col != '') {
                        return true;
                    }
                    return false;
                })
            )
        );
    }

    /**
     * @param array $tableInfo
     * @param array $config
     * @return bool
     */
    public static function modifyPrimaryKeyDecider(array $tableInfo, array $config)
    {
        $configPK = self::normalizePrimaryKey($config["primary_key"]);
        if (count($tableInfo["primaryKey"]) != count($configPK)) {
            return true;
        }
        if (count(array_intersect($tableInfo["primaryKey"], $configPK)) != count($tableInfo["primaryKey"])) {
            return true;
        }
        return false;
    }
}
