<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class StorageApiReaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $tmpDir;

    public function setUp()
    {
        // Create folders
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);
        $fs->mkdir($root . "/download");
        $this->tmpDir = $root;

        $this->client = new Client(array("token" => STORAGE_API_TOKEN));

        // Create bucket
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker Testsuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.test")) {
            $csv = new CsvFile($this->tmpDir . "/upload.csv");
            $csv->writeRow(array("Id", "Name"));
            $csv->writeRow(array("test", "test"));
            $this->client->createTableAsync("in.c-docker-test", "test", $csv);
            $this->client->setTableAttribute("in.c-docker-test.test", "attr1", "val1");
        }
    }

    public function tearDown()
    {
        // Delete local files
        $finder = new Finder();
        $fs = new Filesystem();
        $fs->remove($finder->files()->in($this->tmpDir . "/download"));
        $fs->remove($finder->files()->in($this->tmpDir));
        $fs->remove($this->tmpDir . "/download");
        $fs->remove($this->tmpDir);

        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        // Delete tables
        foreach ($this->client->listTables("in.c-docker-test") as $table) {
            $this->client->dropTable($table["id"]);
        }

        // Delete bucket
        $this->client->dropBucket("in.c-docker-test");
    }

    /**
     * @throws \Keboola\StorageApi\ClientException
     */
    public function testReadFiles()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");

        $id1 = $this->client->uploadFile($root . "/upload", (new FileUploadOptions())->setTags(array("docker-bundle-test")));
        $id2 = $this->client->uploadFile($root . "/upload", (new FileUploadOptions())->setTags(array("docker-bundle-test")));

        $reader = new Reader($this->client);
        $configuration = array("tags" => array("docker-bundle-test"));
        $reader->downloadFiles($configuration, $root . "/download");

        $this->assertEquals("test", file_get_contents($root . "/download/" . $id1));
        $this->assertEquals("test", file_get_contents($root . "/download/" . $id2));

        $adapter = new Configuration\Input\File\Manifest\Adapter();
        $manifest1 = $adapter->readFromFile($root . "/download/" . $id1 . ".manifest");
        $manifest2 = $adapter->readFromFile($root . "/download/" . $id2 . ".manifest");

        $this->assertEquals($id1, $manifest1["id"]);
        $this->assertEquals($id2, $manifest2["id"]);
    }

    /**
     *
     */
    public function testReadTables()
    {
        $root = $this->tmpDir;

        $reader = new Reader($this->client);
        $configuration = array(
            array(
                "source" => "in.c-docker-test.test",
                "destination" => "test"
            )
        );

        $reader->downloadTables($configuration, $root . "/download");

        $this->assertEquals("\"Id\",\"Name\"\n\"test\",\"test\"\n", file_get_contents($root . "/download/test.csv"));
        $adapter = new Configuration\Input\Table\Manifest\Adapter();
        $manifest = $adapter->readFromFile($root . "/download/test.csv.manifest");

        $this->assertEquals("in.c-docker-test.test", $manifest["id"]);
        $this->assertEquals("val1", $manifest["attributes"][0]["value"]);

    }
}
