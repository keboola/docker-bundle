<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\TableExporter;
use Keboola\Temp\Temp;
use Keboola\Syrup\Exception\UserException;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class StorageApiHeadlessWriterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Temp
     */
    private $tmp;

    protected function clearBucket()
    {
        foreach (['out.c-docker-test', 'out.c-docker-default-test', 'out.c-docker-redshift-test', 'in.c-docker-test'] as $bucket) {
            try {
                $this->client->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    protected function clearFileUploads()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(['docker-bundle-test']);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    public function setUp()
    {
        // Create folders
        $this->tmp = new Temp();
        $this->tmp->initRunFolder();
        $root = $this->tmp->getTmpFolder();
        $fs = new Filesystem();
        $fs->mkdir($root . DIRECTORY_SEPARATOR . 'upload');
        $fs->mkdir($root . DIRECTORY_SEPARATOR . 'download');

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $this->clearBucket();
        $this->clearFileUploads();
        $this->client->createBucket('docker-redshift-test', 'out', '', 'redshift');
        $this->client->createBucket('docker-default-test', 'out');
    }

    public function tearDown()
    {
        // Delete local files
        $this->tmp = null;

        $this->clearBucket();
        $this->clearFileUploads();
    }

    public function testWriteTableOutputMapping()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/table1.csv", "\"test\",\"test\"\n\"aabb\",\"ccdd\"\n");

        $configs = array(
            array(
                "source" => "table1.csv",
                "destination" => "out.c-docker-test.table1",
                "columns" => ["Id","Name"]
            )
        );

        $writer = new Writer($this->client, (new Logger("null"))->pushHandler(new NullHandler()));

        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $table = $this->client->getTable("out.c-docker-test.table1");
        $this->assertEquals(["Id", "Name"], $table["columns"]);
    }

    public function testWriteTableOutputMappingEmptyFile()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/table1", "");

        $configs = array(
            array(
                "source" => "table1",
                "destination" => "out.c-docker-test.table1",
                "columns" => ["Id","Name"]
            )
        );

        $writer = new Writer($this->client, (new Logger("null"))->pushHandler(new NullHandler()));
        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        $table = $this->client->getTable("out.c-docker-test.table1");
        $this->assertEquals(["Id", "Name"], $table["columns"]);
    }

    public function testWriteTableOutputMappingAndManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/table2.csv",
            "\"test\",\"test\"\n"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/table2.csv.manifest",
            "{\"destination\": \"out.c-docker-test.table2\",\"primary_key\":[\"Id\"],\"columns\":[\"a\",\"b\"]}"
        );

        $configs = array(
            array(
                "source" => "table2.csv",
                "destination" => "out.c-docker-test.table",
                "columns" => ["Id", "Name"]
            )
        );

        $writer = new Writer($this->client, (new Logger("null"))->pushHandler(new NullHandler()));

        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table', $tables[0]["id"]);
        $table = $this->client->getTable("out.c-docker-test.table");
        $this->assertEquals(array(), $table["primaryKey"]);
        $this->assertEquals(["Id", "Name"], $table["columns"]);
    }

    public function testWriteTableManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv",
            "\"test\",\"test\"\n"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv.manifest",
            "{\"destination\": \"out.c-docker-test.table3\",\"primary_key\":[\"Id\",\"Name\"],\"columns\":[\"Id\",\"Name\"]}"
        );

        $writer = new Writer($this->client, (new Logger("null"))->pushHandler(new NullHandler()));

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table3', $tables[0]["id"]);
        $table = $this->client->getTable("out.c-docker-test.table3");
        $this->assertEquals(array("Id", "Name"), $table["primaryKey"]);
        $this->assertEquals(array("Id", "Name"), $table["columns"]);
    }
}
