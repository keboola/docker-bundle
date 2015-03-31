<?php

namespace Keboola\DockerBundle\Docker\StorageApi;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration\Output\File;
use Keboola\DockerBundle\Docker\Configuration\Output\Table;
use Keboola\DockerBundle\Exception\ManifestMismatchException;
use Keboola\StorageApi\Client;
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
        $finder = new Finder();
        $manifests = $finder->files()->name("*.manifest")->in($source);
        $manifestNames = [];
        /** @var SplFileInfo $manifest */
        foreach ($manifests as $manifest) {
            $manifestNames[$manifest->getPathName()] = 1;
        }

        $files = $finder->files()->notName("*.manifest")->in($source);

        $outputMappingFiles = array();
        foreach ($configurations as $config) {
            $outputMappingFiles[] = $config["source"];
        }
        $outputMappingFiles = array_unique($outputMappingFiles);
        $processedOutputMappingFiles = array();

        /**
         * @var $file SplFileInfo
         */
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
            if (isset($manifestNames[$file->getPathname() . ".manifest"])) {
                $configFromManifest = $this->readFileManifest($file->getPathname() . ".manifest");
                unset($manifestNames[$file->getPathname() . ".manifest"]);
            }

            try {
                $storageConfig = (new File\Manifest())->parse(array($configFromMapping, $configFromManifest));
            } catch (InvalidConfigurationException $e) {
                throw new UserException("Failed to write manifest for table {$file->getFilename()}.", $e);
            }

            $this->uploadFile($file->getPathname(), $storageConfig);
        }

        $processedOutputMappingFiles = array_unique($processedOutputMappingFiles);
        $diff = array_diff(
            array_merge($outputMappingFiles, $processedOutputMappingFiles),
            $processedOutputMappingFiles
        );
        if (count($diff)) {
            throw new UserException("Couldn't process output mapping for file(s) '" . join("', '", $diff) . "'.");
        }
        if (count($manifestNames) > 0) {
            throw new ManifestMismatchException("Found orphaned file manifests: " . implode("', '", array_keys($manifestNames)));
        }
    }

    /**
     * @param $source
     * @return array
     * @throws \Exception
     */
    protected function readFileManifest($source)
    {
        $adapter = new File\Manifest\Adapter($this->getFormat());
        return $adapter->readFromFile($source);
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
        $fs = new Filesystem();
        $finder = new Finder();

        $files = $finder->files()->name("*.csv")->in($source);

        $outputMappingTables = array();
        foreach ($configurations as $config) {
            $outputMappingTables[] = $config["source"];
        }
        $outputMappingTables = array_unique($outputMappingTables);
        $processedOutputMappingTables = array();

        /**
         * @var $file SplFileInfo
         */
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
            if ($fs->exists($file->getPathname() . ".manifest")) {
                $configFromManifest = $this->readTableManifest($file->getPathname() . ".manifest");
            } else {
                // If no manifest found and no output mapping, use filename (without .csv) as table id
                if (!isset($configFromMapping["destination"])) {
                    $configFromMapping["destination"] = substr(
                        $file->getFilename(),
                        0,
                        strlen($file->getFilename()) - 4
                    );
                }
            }

            try {
                $config = (new Table\Manifest())->parse(array($configFromMapping, $configFromManifest));
            } catch (InvalidConfigurationException $e) {
                throw new UserException("Failed to write manifest for table {$file->getFilename()}.", $e);
            }

            if (count(explode(".", $config["destination"])) != 3) {
                throw new UserException("'{$config["destination"]}' does not seem to be a table identifier.");
            }

            $this->uploadTable($file->getPathname(), $config);
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
}
