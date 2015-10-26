<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Job\Executor;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\DockerBundle\Encryption\JobCryptoWrapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class FunctionalTests extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Temp
     */
    private $temp;


    public function setUp()
    {
        $this->client = new Client(["token" => STORAGE_API_TOKEN]);
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();

        if ($this->client->bucketExists("out.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("out.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-test");
        }

    }


    public function tearDown()
    {
        if ($this->client->bucketExists("in.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("in.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("in.c-docker-test");
        }
        if ($this->client->bucketExists("out.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("out.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-test");
        }
        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(array("docker-bundle-test"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        // clean temporary folder
        $fs = new Filesystem();
        $fs->remove($this->temp->getTmpFolder());
    }


    public function testRDocker()
    {
        $data = [
            'params' => [
                'component' => 'docker-r',
                'mode' => 'run',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ]
                    ]
                ]
            ]
        ];

        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-r');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $job = new Job($configEncryptor, $data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            $csv->writeRow(['price', '100', '1000']);
            $csv->writeRow(['size', 'small', 'big']);
            $csv->writeRow(['age', 'low', 'high']);
            $csv->writeRow(['kindness', 'no', 'yes']);
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $sapiService = new StorageApiService();
        $sapiService->setClient($this->client);
        $componentsService = new ComponentsService($sapiService);
        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsService, $jobCryptoWrapper);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        $csvData = $this->client->exportTable('out.c-docker-test.transposed');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);
    }


    public function testSandbox()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("sandbox"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        $data = [
            'params' => [
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                ],
                'mode' => 'sandbox',
                'format' => 'yaml'
            ]
        ];
        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-r');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $job = new Job($configEncryptor, $data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
            $fs = new Filesystem();
            unset($csv);
            $fs->remove($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $sapiService = new StorageApiService();
        $sapiService->setClient($this->client);
        $componentsService = new ComponentsService($sapiService);
        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsService, $jobCryptoWrapper);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['sandbox']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertLessThan(4000, $files[0]['sizeBytes']);
    }


    public function testInput()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("input"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        $data = [
            'params' => [
                'component' => 'docker-r',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ],
                    ]
                ],
                'mode' => 'input',
                'format' => 'yaml'
            ]
        ];
        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-r');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $job = new Job($configEncryptor, $data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $sapiService = new StorageApiService();
        $sapiService->setClient($this->client);
        $componentsService = new ComponentsService($sapiService);
        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsService, $jobCryptoWrapper);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['input']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(3900, $files[0]['sizeBytes']);
    }


    public function testDryRun()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("dry-run"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        $data = [
            'params' => [
                'component' => 'docker-r',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ],
                    ]
                ],
                'mode' => 'dry-run',
                'format' => 'yaml'
            ]
        ];
        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-r');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $job = new Job($configEncryptor, $data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $sapiService = new StorageApiService();
        $sapiService->setClient($this->client);
        $componentsService = new ComponentsService($sapiService);
        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsService, $jobCryptoWrapper);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['dry-run']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(6300, $files[0]['sizeBytes']);

    }


    public function testHelloWorld()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "hello-world"
            )
        );

        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-test');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor = new ObjectEncryptor($jobCryptoWrapper);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);

        $container = new Container($image, $log);
        $container->setId("hello-world");
        $container->setDataDir("/tmp");
        $process = $container->run("testsuite", []);
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Docker", trim($process->getOutput()));
    }


    /**
     * @expectedException \Keboola\Syrup\Exception\ApplicationException
     */
    public function testException()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "hello-world"
            )
        );

        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-test');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor = new ObjectEncryptor($jobCryptoWrapper);
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);

        $container = new Container($image, $log);
        $container->run("testsuite", []);
    }


    public function testIncrementalTags()
    {
        $data = [
            'params' => [
                'component' => 'docker-r',
                'mode' => 'run',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'files' => [[
                                'query' => 'tags: toprocess AND NOT tags: downloaded',
                                'processed_tags' => [
                                    'downloaded', 'experimental'
                                ],
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            "inDirectory <- '/data/in/files/'",
                            "outDirectory <- '/data/out/files/'",
                            "files <- list.files(inDirectory, pattern = '^[0-9]+_upload$', full.names = FALSE)",
                            "for (file in files) {",
                            "    fn <- paste0(outDirectory, file, '.csv');",
                            "    file.copy(paste0(inDirectory, file), fn);",
                            "    wrapper.saveFileManifest(fn, c('processed', 'docker-bundle-test'))",
                            "}"
                        ]
                    ]
                ]
            ]
        ];

        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-r');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $job = new Job($configEncryptor, $data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create file
        $root = $this->temp->getTmpFolder();
        file_put_contents($root . "/upload", "test");

        $id1 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "toprocess"])
        );
        $id2 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "toprocess"])
        );
        $id3 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "incremental-test"])
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $sapiService = new StorageApiService();
        $sapiService->setClient($this->client);
        $componentsService = new ComponentsService($sapiService);
        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsService, $jobCryptoWrapper);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['downloaded']);
        $files = $this->client->listFiles($listFileOptions);
        $ids = [];
        foreach ($files as $file) {
            $ids[] = $file['id'];
        }
        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        $this->assertNotContains($id3, $ids);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['processed']);
        $files = $this->client->listFiles($listFileOptions);
        $this->assertEquals(2, count($files));
    }


    public function testStoredConfigDecryptNonEncryptComponent()
    {
        $data = [
            'params' => [
                'component' => 'docker-config-dump',
                'mode' => 'run',
                'config' => 1
            ]
        ];

        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-config-dump');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        // mock components
        $configData = [
            "configuration" => [
                "parameters" => [
                    "key1" => "value1",
                    "#key2" => $configEncryptor->encrypt("value2"),
                ]
            ],
            "state" => []
        ];

        $componentsStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Components")
            ->disableOriginalConstructor()
            ->getMock();
        $componentsStub->expects($this->once())
            ->method("getConfiguration")
            ->with("docker-config-dump", 1)
            ->will($this->returnValue($configData));

        $componentsServiceStub = $this->getMockBuilder("\\Keboola\\DockerBundle\\Service\\ComponentsService")
            ->disableOriginalConstructor()
            ->getMock();
        $componentsServiceStub->expects($this->once())
            ->method("getComponents")
            ->will($this->returnValue($componentsStub));

        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsServiceStub, $jobCryptoWrapper);

        // mock client to return image data
        $indexActionValue = array(
            'components' =>
                array (
                    0 =>
                        array (
                            'id' => 'docker-config-dump',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array (
                                'definition' =>
                                    array (
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/config-dump',
                                    ),
                            ),
                            'flags' => array (),
                            'uri' => 'https://syrup.keboola.com/docker/docker-config-dump',
                        )
                )
        );
        $sapiStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue))
        ;
        $jobExecutor->setStorageApi($sapiStub);

        $job = new Job($configEncryptor, $data);

        $response = $jobExecutor->execute($job);
        $config = Yaml::parse($response["message"]);
        $this->assertEquals("KBC::Encrypted==", substr($config["parameters"]["#key2"], 0, 16));
    }

    public function testStoredConfigDecryptEncryptComponent()
    {
        $data = [
            'params' => [
                'component' => 'docker-config-dump',
                'mode' => 'run',
                'config' => 1
            ]
        ];

        $jobCryptoWrapper = new JobCryptoWrapper(hash('sha256', uniqid()));
        $jobCryptoWrapper->setComponentId('docker-config-dump');
        $tokenInfo = $this->client->verifyToken();
        $jobCryptoWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $configEncryptor = new ObjectEncryptor($jobCryptoWrapper);
        $genericEncryptor = new ObjectEncryptor(hash('sha256', uniqid()));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        // mock components
        $configData = [
            "configuration" => [
                "parameters" => [
                    "key1" => "value1",
                    "#key2" => $configEncryptor->encrypt("value2"),
                ]
            ],
            "state" => []
        ];

        $componentsStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Components")
            ->disableOriginalConstructor()
            ->getMock();
        $componentsStub->expects($this->once())
            ->method("getConfiguration")
            ->with("docker-config-dump", 1)
            ->will($this->returnValue($configData));

        $componentsServiceStub = $this->getMockBuilder("\\Keboola\\DockerBundle\\Service\\ComponentsService")
            ->disableOriginalConstructor()
            ->getMock();
        $componentsServiceStub->expects($this->once())
            ->method("getComponents")
            ->will($this->returnValue($componentsStub));

        $jobExecutor = new Executor($log, $this->temp, $genericEncryptor, $configEncryptor, $componentsServiceStub, $jobCryptoWrapper);

        // mock client to return image data
        $indexActionValue = array(
            'components' =>
                array (
                    0 =>
                        array (
                            'id' => 'docker-config-dump',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array (
                                'definition' =>
                                    array (
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/config-dump',
                                    ),
                            ),
                            'flags' => array ('encrypt'),
                            'uri' => 'https://syrup.keboola.com/docker/docker-config-dump',
                        )
                )
        );
        $sapiStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $sapiStub->expects($this->once())
            ->method("verifyToken")
            ->will($this->returnValue($tokenInfo));

        $jobExecutor->setStorageApi($sapiStub);

        $job = new Job($configEncryptor, $data);

        $response = $jobExecutor->execute($job);
        $config = Yaml::parse($response["message"]);
        $this->assertEquals("value2", $config["parameters"]["#key2"]);
    }
}
