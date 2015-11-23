<?php

namespace Keboola\DockerBundle\Docker\StorageApi;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration\Output\File;
use Keboola\DockerBundle\Docker\Configuration\Output\Table;
use Keboola\DockerBundle\Docker\Configuration\State\Adapter;
use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\DockerBundle\Exception\MissingFileException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\FileUploadOptions;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Keboola\Syrup\Exception\UserException;

/**
 * Class Writer
 * @package Keboola\DockerBundle\Docker\StorageApi
 */
class Writer
{

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var
     */
    protected $format = 'yaml';

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
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->setClient($client);
    }

    /**
     * Upload files from local temp directory to Storage.
     *
     * @param string $source Source path.
     * @param array $configurations Upload configurations.
     */
    public function uploadFiles($source, $configurations = array())
    {

        $manifestNames = $this->getManifestFiles($source);

        $finder = new Finder();
        /** @var SplFileInfo[] $files */
        $files = $finder->files()->notName("*.manifest")->in($source);

        $outputMappingFiles = array();
        foreach ($configurations as $config) {
            $outputMappingFiles[] = $config["source"];
        }
        $outputMappingFiles = array_unique($outputMappingFiles);
        $processedOutputMappingFiles = array();

        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $file->getFilename();
        }

        // Check if all files from output mappings are present
        foreach ($configurations as $config) {
            if (!in_array($config["source"], $fileNames)) {
                throw new MissingFileException("File '{$config["source"]}' not found.");
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
            foreach ($configurations as $config) {
                if (isset($config["source"]) && $config["source"] == $file->getFilename()) {
                    $configFromMapping = $config;
                    $processedOutputMappingFiles[] = $configFromMapping["source"];
                    unset($configFromMapping["source"]);
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
     * @param array $configurations
     */
    public function uploadTables($source, $configurations = array())
    {
        $manifestNames = $this->getManifestFiles($source);

        $finder = new Finder();

        /** @var SplFileInfo[] $files */
        $files = $finder->files()->notName("*.manifest")->in($source);

        $outputMappingTables = array();
        foreach ($configurations as $config) {
            $outputMappingTables[] = $config["source"];
        }
        $outputMappingTables = array_unique($outputMappingTables);
        $processedOutputMappingTables = array();

        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[] = $file->getFilename();
        }

        // Check if all files from output mappings are present
        foreach ($configurations as $config) {
            if (!in_array($config["source"], $fileNames)) {
                throw new MissingFileException("Table source '{$config["source"]}' not found.");
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
            foreach ($configurations as $config) {
                if (isset($config["source"]) && $config["source"] == $file->getFilename()) {
                    $configFromMapping = $config;
                    $processedOutputMappingTables[] = $configFromMapping["source"];
                    unset($configFromMapping["source"]);
                }
            }

            $manifestKey = array_search($file->getPathname() . ".manifest", $manifestNames);
            if ($manifestKey !== false) {
                $configFromManifest = $this->readTableManifest($file->getPathname() . ".manifest");
                unset($manifestNames[$manifestKey]);
            } else {
                // If no manifest found and no output mapping, use filename (without .csv if present) as table id
                if (!isset($configFromMapping["destination"])) {
                    // Check for .csv suffix
                    if (substr($file->getFilename(), -4) == '.csv') {
                        $configFromMapping["destination"] = substr(
                            $file->getFilename(),
                            0,
                            strlen($file->getFilename()) - 4
                        );
                    } else {
                        $configFromMapping["destination"] = $file->getFilename();
                    }
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
                    "Output source '{$config["destination"]}' does not seem to be a valid table identifier, " .
                    "either set output-mapping for table stored in '{$file->getRelativePathname()}' or make sure " .
                    "that the file name is a valid storage table identifier."
                );
            }

            try {
                $this->uploadTable($file->getPathname(), $config);
            } catch (ClientException $e) {
                throw new UserException(
                    "Cannot upload file '{$file->getFilename()}' to table '{$config["destination"]}' in Storage API: "
                    . $e->getMessage(),
                    $e
                );
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
     * @param $source
     * @return array
     * @throws \Exception
     */
    protected function readTableManifest($source)
    {
        $adapter = new Table\Manifest\Adapter($this->getFormat());

        return $adapter->readFromFile($source);
    }

    /**
     * @param $source
     * @param array $config
     * @throws \Keboola\StorageApi\ClientException
     */
    protected function uploadTable($source, $config = array())
    {
        $csvFile = new CsvFile($source);
        $tableIdParts = explode(".", $config["destination"]);
        $bucketId = $tableIdParts[0] . "." . $tableIdParts[1];
        $bucketName = substr($tableIdParts[1], 2);
        $tableName = $tableIdParts[2];

        // Create bucket if not exists
        if (!$this->client->bucketExists($bucketId)) {
            // TODO component name!
            $this->client->createBucket($bucketName, $tableIdParts[0], "Created by Docker Bundle");
        }

        if ($this->client->tableExists($config["destination"])) {
            if (isset($config["delete_where_column"]) && $config["delete_where_column"] != '') {
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
            $this->client->writeTableAsync($config["destination"], $csvFile, $options);
        } else {
            $options = array(
                "primaryKey" => join(",", array_unique($config["primary_key"]))
            );
            $this->client->createTableAsync($bucketId, $tableName, $csvFile, $options);
        }
    }

    /**
     * @param $dir
     * @return array
     */
    protected function getManifestFiles($dir)
    {
        $finder = new Finder();
        $manifests = $finder->files()->name("*.manifest")->in($dir);
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
        $reader = new Reader($this->client);
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
     *
     * Read state file from disk and if it's different from previous state update in Storage
     *
     * @param $componentId
     * @param $configurationId
     * @param $file
     * @param $previousState
     */
    public function updateState($componentId, $configurationId, $file, $previousState)
    {
        $adapter = new Adapter($this->getFormat());
        $fileName = $file . $adapter->getFileExtension();
        $fs = new Filesystem();
        if ($fs->exists($fileName)) {
            $currentState = $adapter->readFromFile($fileName);
        } else {
            $currentState = array();
        }
        if (serialize($currentState) != serialize($previousState)) {
            $components = new Components($this->getClient());
            $configuration = new Configuration();
            $configuration->setComponentId($componentId);
            $configuration->setConfigurationId($configurationId);
            $configuration->setState($currentState);
            $components->updateConfiguration($configuration);
        }
    }
}
