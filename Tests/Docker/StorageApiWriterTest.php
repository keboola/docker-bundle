<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\TableExporter;
use Keboola\Temp\Temp;
use Keboola\Syrup\Exception\UserException;
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

        if ($this->client->bucketExists("out.c-docker-redshift-test")) {
            foreach ($this->client->listTables("out.c-docker-redshift-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-redshift-test");
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
        $this->tmp = new Temp();
        $this->tmp->initRunFolder();
        $root = $this->tmp->getTmpFolder();
        $fs = new Filesystem();
        $fs->mkdir($root . DIRECTORY_SEPARATOR . "upload");
        $fs->mkdir($root . DIRECTORY_SEPARATOR . "download");

        $this->client = new Client(array("token" => STORAGE_API_TOKEN));
        $this->clearBucket();
        $this->clearFileUploads();
        $this->client->createBucket("docker-redshift-test", 'out', '', 'redshift');
    }

    /**
     *
     */
    public function tearDown()
    {
        // Delete local files
        $this->tmp = null;

        $this->clearBucket();
        $this->clearFileUploads();

    }

    /**
     * @throws \Keboola\StorageApi\ClientException
     */
    public function testWriteFiles()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file2", "test");
        file_put_contents(
            $root . "/upload/file2.manifest",
            "tags: [\"docker-bundle-test\", \"xxx\"]\nis_public: false"
        );
        file_put_contents($root . "/upload/file3", "test");
        file_put_contents($root . "/upload/file3.manifest", "tags: [\"docker-bundle-test\"]\nis_public: true");

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

        $writer = new Writer($this->client);

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


    /**
     * @throws \Keboola\StorageApi\ClientException
     */
    public function testWriteFilesOutputMapping()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");

        $configs = array(
            array(
                "source" => "file1",
                "tags" => array("docker-bundle-test")
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadFiles($root . "/upload", ["mapping" => $configs]);

        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        $this->assertCount(1, $files);

        $file1 = null;
        foreach ($files as $file) {
            if ($file["name"] == 'file1') {
                $file1 = $file;
            }
        }

        $this->assertNotNull($file1);
        $this->assertEquals(4, $file1["sizeBytes"]);
        $this->assertEquals(array("docker-bundle-test"), $file1["tags"]);
    }


    /**
     */
    public function testWriteFilesOutputMappingAndManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file1.manifest", "tags: [\"docker-bundle-test\", \"xxx\"]\nis_public: true");

        $configs = array(
            array(
                "source" => "file1",
                "tags" => array("docker-bundle-test", "yyy"),
                "is_public" => false
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadFiles($root . "/upload", ["mapping" => $configs]);

        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        $this->assertCount(1, $files);

        $file1 = null;
        foreach ($files as $file) {
            if ($file["name"] == 'file1') {
                $file1 = $file;
            }
        }

        $this->assertNotNull($file1);
        $this->assertEquals(4, $file1["sizeBytes"]);
        $this->assertEquals(array("docker-bundle-test", "yyy"), $file1["tags"]);
        $this->assertFalse($file1["isPublic"]);
    }

    public function testWriteFilesInvalidJson()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file1.manifest", "this is not at all a {valid} json");

        $configs = array(
            array(
                "source" => "file1",
                "tags" => array("docker-bundle-test", "yyy"),
                "is_public" => false
            )
        );

        $writer = new Writer($this->client);
        $writer->setFormat('json');
        try {
            $writer->uploadFiles($root . "/upload", ["mapping" => $configs]);
            $this->fail("Invalid manifest must raise exception.");
        } catch (UserException $e) {
            $this->assertContains('json', $e->getMessage());
        }
    }

    public function testWriteFilesInvalidYaml()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file1.manifest", "\tthis is not \n\t \tat all a {valid} yaml");

        $configs = array(
            array(
                "source" => "file1",
                "tags" => array("docker-bundle-test", "yyy"),
                "is_public" => false
            )
        );

        $writer = new Writer($this->client);
        $writer->setFormat('yaml');
        try {
            $writer->uploadFiles($root . "/upload", ["mapping" => $configs]);
            $this->fail("Invalid manifest must raise exception.");
        } catch (UserException $e) {
            $this->assertContains('yaml', $e->getMessage());
        }
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\MissingFileException
     * @expectedExceptionMessage File 'file2' not found
     */
    public function testWriteFilesOutputMappingMissing()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1", "test");
        file_put_contents($root . "/upload/file1.manifest", "tags: [\"docker-bundle-test-xxx\"]\nis_public: true");

        $configs = array(
            array(
                "source" => "file2",
                "tags" => array("docker-bundle-test"),
                "is_public" => false
            )
        );
        $writer = new Writer($this->client);
        $writer->uploadFiles($root . "/upload", ["mapping" => $configs]);
    }

    /**
     * @expectedException \Keboola\DockerBundle\Exception\ManifestMismatchException
     * @expectedExceptionMessage Found orphaned file manifest: 'file1.manifest'
     */
    public function testWriteFilesOrphanedManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/file1.manifest", "tags: [\"docker-bundle-test-xxx\"]\nis_public: true");
        $writer = new Writer($this->client);
        $writer->uploadFiles($root . "/upload");
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

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table1', $tables[0]["id"]);
    }

    public function testWriteTableOutputMappingWithoutCsv()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/table1", "\"Id\",\"Name\"\n\"test\",\"test\"\n\"aabb\",\"ccdd\"\n");

        $configs = array(
            array(
                "source" => "table1",
                "destination" => "out.c-docker-test.table1"
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table1', $tables[0]["id"]);
    }

    public function testWriteTableOutputMappingAndManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/table2.csv",
            "\"Id\",\"Name\"\n\"test\",\"test\"\n"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/table2.csv.manifest",
            "destination: out.c-docker-test.table2\nprimary_key: [\"Id\"]"
        );

        $configs = array(
            array(
                "source" => "table2.csv",
                "destination" => "out.c-docker-test.table"
            )
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table', $tables[0]["id"]);
        $this->assertEquals(array(), $tables[0]["primaryKey"]);
    }

    public function testWriteTableManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv",
            "\"Id\",\"Name\"\n\"test\",\"test\"\n"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv.manifest",
            "destination: out.c-docker-test.table3\nprimary_key: [\"Id\", \"Name\"]"
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table3', $tables[0]["id"]);
        $this->assertEquals(array("Id", "Name"), $tables[0]["primaryKey"]);
    }

    public function testWriteTableInvalidManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv",
            "\"Id\",\"Name\"\n\"test\",\"test\"\n"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv.manifest",
            "destination: out.c-docker-test.table3\nprimary_key: \"Id\""
        );

        $writer = new Writer($this->client);
        try {
            $writer->uploadTables($root . "/upload");
            $this->fail('Invalid table manifest must cause exception');
        } catch (UserException $e) {
            $this->assertContains('Invalid type for path', $e->getMessage());
        }
    }

    public function testWriteTableManifestCsv()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv",
            "'Id'\t'Name'\n'test'\t'test''s'\n"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/out.c-docker-test.table3.csv.manifest",
            "destination: out.c-docker-test.table3\ndelimiter: \"\t\"\nenclosure: \"'\""
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);
        $this->assertEquals('out.c-docker-test.table3', $tables[0]["id"]);
        $exporter = new TableExporter($this->client);
        $downloadedFile = $root . DIRECTORY_SEPARATOR . "download.csv";
        $exporter->exportTable('out.c-docker-test.table3', $downloadedFile, []);
        $table = $this->client->parseCsv(file_get_contents($downloadedFile));
        $this->assertEquals(1, count($table));
        $this->assertEquals(2, count($table[0]));
        $this->assertArrayHasKey('Id', $table[0]);
        $this->assertArrayHasKey('Name', $table[0]);
        $this->assertEquals('test', $table[0]['Id']);
        $this->assertEquals('test\'s', $table[0]['Name']);
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
            "destination: out.c-docker-redshift-test.table3\ndelimiter: \"\t\"\nenclosure: \"'\"\nescaped_by: \\"
        );

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

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

    /**
     * @expectedException \Keboola\DockerBundle\Exception\ManifestMismatchException
     * @expectedExceptionMessage Found orphaned table manifest: 'table.csv.manifest'
     */
    public function testWriteTableOrphanedManifest()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . "upload/table.csv.manifest",
            "destination: out.c-docker-test.table3\nprimary_key: [\"Id\", \"Name\"]"
        );
        $writer = new Writer($this->client);
        $writer->uploadTables($root . "/upload");
    }


    /**
     * @expectedException \Keboola\DockerBundle\Exception\MissingFileException
     * @expectedExceptionMessage Table source 'table1.csv' not found
     */
    public function testWriteTableOutputMappingMissing()
    {
        $root = $this->tmp->getTmpFolder();

        $configs = array(
            array(
                "source" => "table1.csv",
                "destination" => "out.c-docker-test.table1"
            )
        );
        $writer = new Writer($this->client);
        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);
    }

    public function testWriteTableBare()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/out.c-docker-test.table4.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n");

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);

        $this->assertEquals('out.c-docker-test.table4', $tables[0]["id"]);
        $tableInfo = $this->client->getTable('out.c-docker-test.table4');
        $this->assertEquals(array("Id", "Name"), $tableInfo["columns"]);
    }

    public function testWriteTableBareWithoutSuffix()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/out.c-docker-test.table4", "\"Id\",\"Name\"\n\"test\",\"test\"\n");

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload");

        $tables = $this->client->listTables("out.c-docker-test");
        $this->assertCount(1, $tables);

        $this->assertEquals('out.c-docker-test.table4', $tables[0]["id"]);
        $tableInfo = $this->client->getTable('out.c-docker-test.table4');
        $this->assertEquals(array("Id", "Name"), $tableInfo["columns"]);
    }

    public function testWriteTableIncrementalWithDelete()
    {
        $root = $this->tmp->getTmpFolder();
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

        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);

        // And again, check first incremental table
        $writer->uploadTables($root . "/upload", ["mapping" => $configs]);
        $this->client->exportTable("out.c-docker-test.table1", $root . DIRECTORY_SEPARATOR . "download.csv");
        $table = $this->client->parseCsv(file_get_contents($root . DIRECTORY_SEPARATOR . "download.csv"));
        usort($table, function ($a, $b) {
            return strcasecmp($a['Id'], $b['Id']);
        });
        $this->assertEquals(3, count($table));
        $this->assertEquals(2, count($table[0]));
        $this->assertArrayHasKey('Id', $table[0]);
        $this->assertArrayHasKey('Name', $table[0]);
        $this->assertEquals('aabb', $table[0]['Id']);
        $this->assertEquals('ccdd', $table[0]['Name']);
        $this->assertEquals('test', $table[1]['Id']);
        $this->assertEquals('test', $table[1]['Name']);
        $this->assertEquals('test', $table[2]['Id']);
        $this->assertEquals('test', $table[2]['Name']);
    }

    public function testTagFiles()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/test", "test");

        $id1 = $this->client->uploadFile(
            $root . "/upload/test",
            (new FileUploadOptions())->setTags(array("docker-bundle-test"))
        );
        $id2 = $this->client->uploadFile(
            $root . "/upload/test",
            (new FileUploadOptions())->setTags(array("docker-bundle-test"))
        );

        $writer = new Writer($this->client);
        $configuration = [["tags" => ["docker-bundle-test"], "processed_tags" => ['downloaded']]];
        $writer->tagFiles($configuration);

        $file = $this->client->getFile($id1);
        $this->assertTrue(in_array('downloaded', $file['tags']));
        $file = $this->client->getFile($id2);
        $this->assertTrue(in_array('downloaded', $file['tags']));
    }

    public function testUpdateStateNoChange()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/test.json", '{"state": "aabb"}');

        $sapiStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->never())
            ->method("apiPut")
            ;
        $writer = new Writer($sapiStub);
        $writer->setFormat("json");
        $writer->updateState("test", "test", $root . "/upload/test", ["state" => "aabb"]);
    }

    public function testUpdateStateChange()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/test.json", '{"state": "aabbcc"}');

        $sapiStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with(
                $this->equalTo("storage/components/test/configs/test"),
                $this->equalTo(["state" => '{"state":"aabbcc"}'])
            );

        $writer = new Writer($sapiStub);
        $writer->setFormat("json");
        $writer->updateState("test", "test", $root . "/upload/test", ["state" => "aabb"]);
    }

    public function testUpdateStateChangeToEmpty()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/test.json", '{}');

        $sapiStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with($this->equalTo("storage/components/test/configs/test"), $this->equalTo(["state" => '{}']))
            ;

        $writer = new Writer($sapiStub);
        $writer->setFormat("json");
        $writer->updateState("test", "test", $root . "/upload/test", ["state" => "aabb"]);
    }

    public function testWriteTableToDefaultBucket()
    {
        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/table1.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n");
        file_put_contents($root . "/upload/table2.csv", "\"Id\",\"Name2\"\n\"test2\",\"test2\"\n");

        $writer = new Writer($this->client);

        $writer->uploadTables($root . "/upload", ["bucket" => "in.c-docker-test"]);

        $tables = $this->client->listTables("in.c-docker-test");
        $this->assertCount(2, $tables);

        $this->assertEquals('in.c-docker-test.table1', $tables[0]["id"]);
        $tableInfo = $this->client->getTable('in.c-docker-test.table1');
        $this->assertEquals(array("Id", "Name"), $tableInfo["columns"]);

        $this->assertEquals('in.c-docker-test.table2', $tables[1]["id"]);
        $tableInfo = $this->client->getTable('in.c-docker-test.table2');
        $this->assertEquals(array("Id", "Name2"), $tableInfo["columns"]);
    }
}
