<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\OutputMapping\Writer\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\TableExporter;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class StorageApiWriterTest extends \PHPUnit_Framework_TestCase
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

    public function testWriteFiles()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file2", "test");
        file_put_contents(
            $root . "/upload/file2.manifest",
            "{\"tags\": [\"docker-bundle-test\", \"xxx\"],\"is_public\": false}"
        );
        file_put_contents($root . "/upload/file3", "test");
        file_put_contents($root . "/upload/file3.manifest", "{\"tags\": [\"docker-bundle-test\"],\"is_public\": true}");

        $configs = array(
            array(
                "source" => "file1",
                "tags" => array("docker-bundle-test")
            ),
            array(
                "source" => "file2",
                "tags" => array("docker-bundle-test", "another-tag"),
                "is_public" => true
            )
        );

        $writer = new Writer($this->client, new NullLogger());

        $writer->uploadFiles($root . "/upload", ["mapping" => $configs]);

        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        $this->assertCount(3, $files);

        $file1 = $file2 = $file3 = null;
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
        $this->assertEquals(array("docker-bundle-test", "another-tag"), $file2["tags"]);
        $this->assertEquals(array("docker-bundle-test"), $file3["tags"]);
        $this->assertFalse($file1["isPublic"]);
        $this->assertTrue($file2["isPublic"]);
        $this->assertTrue($file3["isPublic"]);
    }

    public function testWriteTableOutputMapping()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/table1.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n\"aabb\",\"ccdd\"\n");

        $configs = array(
            array(
                "source" => "table1.csv",
                "destination" => "out.c-docker-test.table1"
            )
        );

        $writer = new Writer($this->client, new NullLogger());

        $writer->uploadTables($root . "/upload", ["mapping" => $configs], ['componentId' => 'foo']);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table1', $tables[0]["id"]);
    }

    public function testWriteTableManifestCsvRedshift()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-redshift-test.table3.csv",
            "'Id'\t'Name'\n'test'\t'test''s'\n"
        );
        // TODO: remove the escaped_by parameter as soon as it is removed from manifest
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-redshift-test.table3.csv.manifest",
            "{\"destination\": \"out.c-docker-redshift-test.table3\",\"delimiter\": \"\\t\",\"enclosure\": \"'\",\"escaped_by\": \"\\\\\"}"
        );

        $writer = new Writer($this->client, new NullLogger());

        $writer->uploadTables($root . "/upload", [], ['componentId' => 'foo']);

        $tables = $this->client->listTables("out.c-docker-redshift-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-redshift-test.table3', $tables[0]["id"]);
        $exporter = new TableExporter($this->client);
        $downloadedFile = $root . DIRECTORY_SEPARATOR . "download.csv";
        $exporter->exportTable('out.c-docker-redshift-test.table3', $downloadedFile, []);
        $table = $this->client->parseCsv(file_get_contents($downloadedFile));
        $this->assertEquals(1, count($table));
        $this->assertEquals(2, count($table[0]));
        $this->assertArrayHasKey('Id', $table[0]);
        $this->assertArrayHasKey('Name', $table[0]);
        $this->assertEquals('test', $table[0]['Id']);
        $this->assertEquals('test\'s', $table[0]['Name']);
    }
}
