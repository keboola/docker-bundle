<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\UserException;
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

        if ($this->client->bucketExists("in.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("in.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("in.c-docker-test");
        }

        if ($this->client->bucketExists("in.c-docker-test-redshift")) {
            // Delete tables
            foreach ($this->client->listTables("in.c-docker-test-redshift") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("in.c-docker-test-redshift");
        }
    }

    public function testReadFiles()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");

        $id1 = $this->client->uploadFile($root . "/upload", (new FileUploadOptions())->setTags(array("docker-bundle-test")));
        $id2 = $this->client->uploadFile($root . "/upload", (new FileUploadOptions())->setTags(array("docker-bundle-test")));

        $reader = new Reader($this->client);
        $configuration = [["tags" => ["docker-bundle-test"]]];
        $reader->downloadFiles($configuration, $root . "/download");

        $this->assertEquals("test", file_get_contents($root . "/download/" . $id1));
        $this->assertEquals("test", file_get_contents($root . "/download/" . $id2));

        $adapter = new Configuration\Input\File\Manifest\Adapter();
        $manifest1 = $adapter->readFromFile($root . "/download/" . $id1 . ".manifest");
        $manifest2 = $adapter->readFromFile($root . "/download/" . $id2 . ".manifest");

        $this->assertEquals($id1, $manifest1["id"]);
        $this->assertEquals($id2, $manifest2["id"]);
    }


    public function testParentId()
    {
        $reader = new Reader($this->client);
        $this->client->setRunId('123456789');
        $this->assertEquals('123456789', $reader->getParentRunId());
        $this->client->setRunId('123456789.98765432');
        $this->assertEquals('123456789', $reader->getParentRunId());
        $this->client->setRunId('123456789.98765432.4563456');
        $this->assertEquals('123456789.98765432', $reader->getParentRunId());
        $this->client->setRunId(null);
        $this->assertEquals('', $reader->getParentRunId());
    }


    public function testReadFilesTagsFilterRunId()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");
        $this->client->setRunId('1234567.8901234');
        $reader = new Reader($this->client);

        $id1 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "runId-" . $this->client->getRunId()])
        );
        $id2 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "runId-" . $reader->getParentRunId()])
        );
        $id3 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "runId-" . $reader->getParentRunId()])
        );
        $id4 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test"])
        );

        $configuration = [["tags" => ["docker-bundle-test"], "filterByRunId" => true]];
        $reader->downloadFiles($configuration, $root . "/download");

        $this->assertTrue(file_exists($root . "/download/" . $id2));
        $this->assertTrue(file_exists($root . "/download/" . $id3));
        $this->assertFalse(file_exists($root . "/download/" . $id1));
        $this->assertFalse(file_exists($root . "/download/" . $id4));
    }


    public function testReadFilesEsQueryFilterRunId()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");
        $this->client->setRunId('1234567.8901234');
        $reader = new Reader($this->client);

        $id1 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "runId-" . $this->client->getRunId()])
        );
        $id2 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "runId-" . $reader->getParentRunId()])
        );
        $id3 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "runId-" . $reader->getParentRunId()])
        );
        $id4 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test"])
        );

        $configuration = [["query" => "tags: docker-bundle-test", "filterByRunId" => true]];
        $reader->downloadFiles($configuration, $root . "/download");

        $this->assertTrue(file_exists($root . "/download/" . $id2));
        $this->assertTrue(file_exists($root . "/download/" . $id3));
        $this->assertFalse(file_exists($root . "/download/" . $id1));
        $this->assertFalse(file_exists($root . "/download/" . $id4));
    }


    public function testReadFilesErrors()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload", "test");

        // make at least 10 files in the project
        for ($i = 0; $i < 12; $i++) {
            $this->client->uploadFile($root . "/upload", (new FileUploadOptions())->setTags(array("docker-bundle-test")));
        }

        $reader = new Reader($this->client);
        $configuration = [];
        $reader->downloadFiles($configuration, $root . "/download");


        $reader = new Reader($this->client);
        $configuration = [[]];
        try {
            $reader->downloadFiles($configuration, $root . "/download");
            $this->fail("Invalid configuration should fail.");
        } catch (UserException $e) {
        }

        $reader = new Reader($this->client);
        $configuration = [['query' => 'id: >0']];
        try {
            $reader->downloadFiles($configuration, $root . "/download");
            $this->fail("Too broad query should fail.");
        } catch (UserException $e) {
        }

    }


    /**
     *
     */
    public function testReadTablesMysql()
    {
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

        $root = $this->tmpDir;

        $reader = new Reader($this->client);
        $configuration = array(
            array(
                "source" => "in.c-docker-test.test",
                "destination" => "test.csv"
            )
        );

        $reader->downloadTables($configuration, $root . "/download");

        $this->assertEquals("\"Id\",\"Name\"\n\"test\",\"test\"\n", file_get_contents($root . "/download/test.csv"));

        $adapter = new Configuration\Input\Table\Manifest\Adapter();

        $manifest = $adapter->readFromFile($root . "/download/test.csv.manifest");
        $this->assertEquals("in.c-docker-test.test", $manifest["id"]);
        $this->assertEquals("val1", $manifest["attributes"][0]["value"]);
    }

    /**
     *
     */
    public function testReadTablesRedshift()
    {
        // Create bucket
        if (!$this->client->bucketExists("in.c-docker-test-redshift")) {
            $this->client->createBucket("docker-test-redshift", Client::STAGE_IN, "Docker Testsuite", "redshift");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test-redshift.test")) {
            $csv = new CsvFile($this->tmpDir . "/upload.csv");
            $csv->writeRow(array("Id", "Name"));
            $csv->writeRow(array("test", "test"));
            $this->client->createTableAsync("in.c-docker-test-redshift", "test", $csv);
            $this->client->setTableAttribute("in.c-docker-test-redshift.test", "attr1", "val2");
        }

        $root = $this->tmpDir;

        $reader = new Reader($this->client);
        $configuration = array(
            array(
                "source" => "in.c-docker-test-redshift.test",
                "destination" => "test-redshift.csv"
            )
        );

        $reader->downloadTables($configuration, $root . "/download");

        $this->assertEquals("\"Id\",\"Name\"\n\"test\",\"test\"\n", file_get_contents($root . "/download/test-redshift.csv"));

        $adapter = new Configuration\Input\Table\Manifest\Adapter();

        $manifest = $adapter->readFromFile($root . "/download/test-redshift.csv.manifest");
        $this->assertEquals("in.c-docker-test-redshift.test", $manifest["id"]);
        $this->assertEquals("val2", $manifest["attributes"][0]["value"]);
    }
}
