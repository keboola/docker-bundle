<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\ListFilesOptions;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class StorageApiWriterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * @throws \Exception
     * @throws \Keboola\StorageApi\ClientException
     */
    protected function clearBucket()
    {
        // Delete tables and bucket
        if ($this->client->bucketExists("out.c-docker-test")) {
            foreach ($this->client->listTables("out.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-test");
        }
    }

    /**
     *
     */
    protected function clearFileUploads()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }
    }

    /**
     *
     */
    public function setUp()
    {
        // Create folders
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);
        $fs->mkdir($root . "/upload");
        $fs->mkdir($root . "/download");
        $this->tmpDir = $root;

        $this->client = new Client(array("token" => STORAGE_API_TOKEN));
        $this->clearBucket();
        $this->clearFileUploads();
    }

    /**
     *
     */
    public function tearDown()
    {
        // Delete local files
        $finder = new Finder();
        $fs = new Filesystem();
        $fs->remove($finder->files()->in($this->tmpDir . "/upload"));
        $fs->remove($finder->files()->in($this->tmpDir . "/download"));
        $fs->remove($finder->files()->in($this->tmpDir));
        $fs->remove($this->tmpDir . "/upload");
        $fs->remove($this->tmpDir . "/download");
        $fs->remove($this->tmpDir);

        $this->clearBucket();
        $this->clearFileUploads();

    }

    /**
     * @throws \Keboola\StorageApi\ClientException
     */
    public function testWriteFiles()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file2", "test");
        file_put_contents($root . "/upload/file2.manifest", "tags: [\"docker-bundle-test\"]\nis_public: false");
        file_put_contents($root . "/upload/file3", "test");
        file_put_contents($root . "/upload/file3.manifest", "tags: [\"docker-bundle-test\"]\nis_public: true");

        $configs = array(
            array(
                "source" => "file1",
                "tags" => array("docker-bundle-test")
            ),
            array(
                "source" => "file2",
                "tags" => array("another-tag"),
                "is_public" => true
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadFiles($root . "/upload", $configs);

        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        $this->assertCount(3, $files);

        foreach ($files as $file) {
            if ($file["name"] == 'file1') {
                $file1 = $file;
            }
            if ($file["name"] == 'file2') {
                $file2 = $file;
            }
            if ($file["name"] == 'file3') {
                $file3 = $file;
            }
        }

        $this->assertNotNull($file1);
        $this->assertNotNull($file2);
        $this->assertNotNull($file3);
        $this->assertEquals(4, $file1["sizeBytes"]);
        $this->assertEquals(array("docker-bundle-test"), $file1["tags"]);
        $this->assertEquals(array("another-tag", "docker-bundle-test"), $file2["tags"]);
        $this->assertEquals(array("docker-bundle-test"), $file3["tags"]);
        $this->assertFalse($file1["isPublic"]);
        $this->assertFalse($file2["isPublic"]);
        $this->assertTrue($file3["isPublic"]);
    }

    /**
     *
     */
    public function testWriteTableOutputMapping()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload/table1.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n\"aabb\",\"ccdd\"\n");

        $configs = array(
            array(
                "source" => "table1.csv",
                "destination" => "out.c-docker-test.table1"
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", $configs);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table1', $tables[0]["id"]);
    }

    /**
     *
     */
    public function testWriteTableOutputMappingAndManifest()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload/table2.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n");
        file_put_contents($root . "/upload/table2.csv.manifest", "destination: out.c-docker-test.table2\nprimary_key: [\"Id\"]");

        $configs = array(
            array(
                "source" => "table2.csv",
                "destination" => "out.c-docker-test.table"
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", $configs);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table2', $tables[0]["id"]);
        $this->assertEquals(array("Id"), $tables[0]["primaryKey"]);

    }

    public function testWriteTableManifest()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload/out.c-docker-test.table3.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n");
        file_put_contents($root . "/upload/out.c-docker-test.table3.csv.manifest", "destination: out.c-docker-test.table3\nprimary_key: [\"Id\", \"Name\"]");

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table3', $tables[0]["id"]);
        $this->assertEquals(array("Id", "Name"), $tables[0]["primaryKey"]);
    }

    /**
     *
     */
    public function testWriteTableBare()
    {
        $root = $this->tmpDir;
        file_put_contents($root . "/upload/out.c-docker-test.table4.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n");

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);

        $this->assertEquals('out.c-docker-test.table4', $tables[0]["id"]);
        $tableInfo = $this->client->getTable('out.c-docker-test.table4');
        $this->assertEquals(array("Id", "Name"), $tableInfo["columns"]);
    }

    /**
     *
     */
    public function testWriteTableIncrementalWithDelete()
    {

        $root = $this->tmpDir;
        file_put_contents($root . "/upload/table1.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n\"aabb\",\"ccdd\"\n");

        $configs = array(
            array(
                "source" => "table1.csv",
                "destination" => "out.c-docker-test.table1",
                "delete_where_column" => "Id",
                "delete_where_values" => array("aabb"),
                "delete_where_operator" => "eq",
                "incremental" => true
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", $configs);

        // And again, check first incremental table
        $writer->uploadTables($root . "/upload", $configs);
        $this->client->exportTable("out.c-docker-test.table1", $root . "/download.csv");
        $this->assertEquals("\"Id\",\"Name\"\n\"test\",\"test\"\n\"test\",\"test\"\n\"aabb\",\"ccdd\"\n", file_get_contents($root . "/download.csv"));
    }
}
