<?php

namespace Keboola\DockerBundle\Tests;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Temp\Temp;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DebugModeTest extends KernelTestCase
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
        parent::setUp();
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]
        );
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        foreach ($this->client->listBuckets() as $bucket) {
            $this->client->dropBucket($bucket["id"], ["force" => true]);
        }

        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(["docker-bundle-test", "debug"]);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        // Create buckets
        $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");

        self::bootKernel();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
    }

    public function tearDown()
    {
        // remove env variables
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        parent::tearDown();
    }

    private function getJobExecutor(ObjectEncryptorFactory $encryptorFactory, array $configuration, HandlerInterface $handler = null)
    {
        list($runner, $componentsService, $loggersServiceStub, $tokenData) = $this->getRunner($encryptorFactory, $configuration);
        if ($handler) {
            /** @var LoggersService $loggersServiceStub */
            $loggersServiceStub->getContainerLog()->pushHandler($handler);
        }
        $encryptorFactory->setProjectId($tokenData["owner"]["id"]);

        $jobExecutor = new Executor(
            $loggersServiceStub->getLog(),
            $runner,
            $encryptorFactory,
            $componentsService,
            self::$kernel->getContainer()->getParameter('storage_api.url')
        );
        $jobExecutor->setStorageApi($this->client);

        return $jobExecutor;
    }

    private function getRunner(&$encryptorFactory, array $configuration)
    {
        $storageApiClient = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
                'userAgent' => 'docker-bundle',
            ]
        );
        $tokenData = $storageApiClient->verifyToken();

        $storageServiceStub = self::getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $storageServiceStub->expects(self::any())
            ->method("getClient")
            ->will(self::returnValue($this->client))
        ;
        $storageServiceStub->expects(self::any())
            ->method("getTokenData")
            ->will(self::returnValue($tokenData))
        ;

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger("null");
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = self::getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $loggersServiceStub->expects(self::any())
            ->method("getLog")
            ->will($this->returnValue($log))
        ;
        $loggersServiceStub->expects(self::any())
            ->method("getContainerLog")
            ->will($this->returnValue($containerLogger))
        ;

        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $componentsStub = self::getMockBuilder(Components::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $componentsStub->expects(self::any())
            ->method("getConfiguration")
            ->with("keboola.python-transformation", "my-config")
            ->will(self::returnValue($configuration))
        ;

        $componentsServiceStub = self::getMockBuilder(ComponentsService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $componentsServiceStub->expects(self::any())
            ->method("getComponents")
            ->will(self::returnValue($componentsStub))
        ;

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        /** @var JobMapper $jobMapperStub */
        $runner = new Runner(
            $encryptorFactory,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapperStub,
            "dummy",
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
        return [$runner, $componentsServiceStub, $loggersServiceStub, $tokenData];
    }

    private function downloadFile($fileId)
    {
        $fileInfo = $this->client->getFile($fileId, (new GetFileOptions())->setFederationToken(true));
        // Initialize S3Client with credentials from Storage API
        $target = $this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'downloaded-data.zip';
        $s3Client = new S3Client([
            'version' => '2006-03-01',
            'region' => $fileInfo['region'],
            'retries' => $this->client->getAwsRetries(),
            'credentials' => [
                'key' => $fileInfo["credentials"]["AccessKeyId"],
                'secret' => $fileInfo["credentials"]["SecretAccessKey"],
                'token' => $fileInfo["credentials"]["SessionToken"],
            ],
            'http' => [
                'decode_content' => false,
            ],
        ]);
        $s3Client->getObject(array(
            'Bucket' => $fileInfo["s3Path"]["bucket"],
            'Key' => $fileInfo["s3Path"]["key"],
            'SaveAs' => $target,
        ));
        return $target;
    }

    public function testDebugModeInline()
    {
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 4; $i++) {
                $csv->writeRow([$i, $i * 100, '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.python-transformation');
        $jobExecutor = $this->getJobExecutor($encryptorFactory, []);
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-docker-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-docker-test.modified',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'plain' => 'not-secret',
                        'script' => [
                            'import csv',
                            'with open("/data/in/tables/source.csv", mode="rt", encoding="utf-8") as in_file, open("/data/out/tables/destination.csv", mode="wt", encoding="utf-8") as out_file:',
                            '   lazy_lines = (line.replace("\0", "") for line in in_file)',
                            '   reader = csv.DictReader(lazy_lines, dialect="kbc")',
                            '   writer = csv.DictWriter(out_file, dialect="kbc", fieldnames=reader.fieldnames)',
                            '   writer.writeheader()',
                            '   for row in reader:',
                            '      writer.writerow({"name": row["name"], "oldValue": row["oldValue"] + "ping", "newValue": row["newValue"] + "pong"})',
                        ],
                    ],
                ],
            ],
        ];

        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        // check that output mapping was not done
        try {
            $this->client->getTableDataPreview('out.c-docker-test.modified');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->client->listFiles($listOptions);
        self::assertEquals(2, count($files));
        self::assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        self::assertContains('stage-last', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[1]['name']));
        self::assertContains('stage-0', $files[1]['tags']);
        self::assertGreaterThan(1000, $files[1]['sizeBytes']);

        $fileName = $this->downloadFile($files[1]['id']);
        $zipArchive = new \ZipArchive();
        $zipArchive->open($fileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode($config, true);
        self::assertEquals('not-secret', $config['parameters']['plain']);
        self::assertArrayHasKey('script', $config['parameters']);
        $tableData = $zipArchive->getFromName('in/tables/source.csv');
        $lines = explode("\n", trim($tableData));
        sort($lines);
        self::assertEquals(
            [
                "\"0\",\"0\",\"1000\"",
                "\"1\",\"100\",\"1000\"",
                "\"2\",\"200\",\"1000\"",
                "\"3\",\"300\",\"1000\"",
                "\"name\",\"oldValue\",\"newValue\"",
            ],
            $lines
        );
        $zipArchive->close();
        unlink($fileName);

        $fileName = $this->downloadFile($files[0]['id']);
        $zipArchive = new \ZipArchive();
        $zipArchive->open($fileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode($config, true);
        self::assertEquals('not-secret', $config['parameters']['plain']);
        self::assertArrayHasKey('script', $config['parameters']);
        $tableData = $zipArchive->getFromName('out/tables/destination.csv');
        $lines = explode("\n", trim($tableData));
        sort($lines);
        self::assertEquals(
            [
                "0,0ping,1000pong",
                "1,100ping,1000pong",
                "2,200ping,1000pong",
                "3,300ping,1000pong",
                "name,oldValue,newValue",
            ],
            $lines
        );
    }

    public function testDebugModeConfiguration()
    {
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 100; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.python-transformation');

        $handler = new TestHandler();
        $configuration = [
            'id' => 'my-config',
            'version' => 1,
            'state' => [],
            'rows' => [],
            'configuration' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'source.csv',
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'destination.csv',
                                'destination' => 'out.c-docker-test.modified',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'plain' => 'not-secret',
                    '#encrypted' => $encryptorFactory->getEncryptor()->encrypt('secret'),
                    'script' => [
                        'from pathlib import Path',
                        'import sys',
                        'import base64',
                        // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                        'contents = Path("/data/config.json").read_text()[::-1]',
                        'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                        'from shutil import copyfile',
                        'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination.csv")',
                    ],
                ],
            ],
        ];
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $configuration, $handler);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => 'my-config',
            ],
        ];

        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        // check that output mapping was not done
        try {
            $this->client->getTableDataPreview('out.c-docker-test.modified');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        // check that the component got deciphered values
        $output = '';
        foreach ($handler->getRecords() as $record) {
            if ($record['level'] == 400) {
                $output = $record['message'];
            }
        }
        $config = \GuzzleHttp\json_decode(strrev(base64_decode($output)), true);
        self::assertEquals('secret', $config['parameters']['#encrypted']);
        self::assertEquals('not-secret', $config['parameters']['plain']);

        // check that the files were stored
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->client->listFiles($listOptions);
        self::assertEquals(2, count($files));
        self::assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        self::assertEquals(0, strcasecmp('data.zip', $files[1]['name']));
        self::assertGreaterThan(2000, $files[0]['sizeBytes']);
        self::assertGreaterThan(2000, $files[1]['sizeBytes']);

        // check that the archive does not contain the decrypted value
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['stage-0']);
        $files = $this->client->listFiles($listOptions);
        self::assertEquals(1, count($files));
        $zipFileName = $this->downloadFile($files[0]["id"]);
        $zipArchive = new \ZipArchive();
        $zipArchive->open($zipFileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode($config, true);
        self::assertNotEquals('secret', $config['parameters']['#encrypted']);
        self::assertStringStartsWith('KBC::Encrypted', $config['parameters']['#encrypted']);
        self::assertEquals('not-secret', $config['parameters']['plain']);
    }

    public function testConfigurationRows()
    {
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 100; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => 'my-config',
            ],
        ];

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.python-transformation');
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $this->getConfiguration());
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->client->getTableDataPreview('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->client->listFiles($listOptions);
        self::assertEquals(4, count($files));
        self::assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        self::assertContains('row2', $files[0]['tags']);
        self::assertContains('stage-last', $files[0]['tags']);
        self::assertGreaterThan(1500, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[1]['name']));
        self::assertContains('row2', $files[1]['tags']);
        self::assertContains('stage-0', $files[1]['tags']);
        self::assertGreaterThan(1500, $files[1]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[2]['name']));
        self::assertContains('row1', $files[2]['tags']);
        self::assertContains('stage-last', $files[2]['tags']);
        self::assertGreaterThan(1500, $files[2]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[3]['name']));
        self::assertContains('row1', $files[3]['tags']);
        self::assertContains('stage-0', $files[3]['tags']);
        self::assertGreaterThan(1500, $files[3]['sizeBytes']);
    }

    private function getConfiguration()
    {
        return [
            'id' => 'my-config',
            'version' => 1,
            'state' => [],
            'rows' => [
                [
                    'id' => 'row1',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
                        'storage' => [
                            'input' => [
                                'tables' => [
                                    [
                                        'source' => 'in.c-docker-test.source',
                                        'destination' => 'source.csv',
                                    ],
                                ],
                            ],
                            'output' => [
                                'tables' => [
                                    [
                                        'source' => 'destination.csv',
                                        'destination' => 'out.c-docker-test.destination',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'script' => [
                                'from shutil import copyfile',
                                'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination.csv")',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'row2',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
                        'storage' => [
                            'input' => [
                                'tables' => [
                                    [
                                        'source' => 'in.c-docker-test.source',
                                        'destination' => 'source.csv',
                                    ],
                                ],
                            ],
                            'output' => [
                                'tables' => [
                                    [
                                        'source' => 'destination-2.csv',
                                        'destination' => 'out.c-docker-test.destination-2',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'script' => [
                                'from shutil import copyfile',
                                'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination-2.csv")',
                            ],
                        ],
                    ],
                ],
            ],
            'configuration' => [],
        ];
    }

    public function testConfigurationRowsProcessors()
    {
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 100; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => 'my-config',
            ],
        ];

        $configuration = $this->getConfiguration();
        $configuration['rows'][0]['configuration']['processors'] = [
            'after' => [
                [
                    "definition" => [
                        "component" => "keboola.processor-create-manifest"
                    ],
                    "parameters" => [
                       "columns_from" => "header"
                    ],
                ],
                [
                    "definition" => [
                        "component" => "keboola.processor-add-row-number-column"
                    ],
                ],
            ],
        ];
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.python-transformation');
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $configuration);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->client->getTableDataPreview('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->client->listFiles($listOptions);
        self::assertEquals(6, count($files));
        self::assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        self::assertContains('row2', $files[0]['tags']);
        self::assertContains('stage-last', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[1]['name']));
        self::assertContains('row2', $files[1]['tags']);
        self::assertContains('stage-0', $files[1]['tags']);
        self::assertGreaterThan(1000, $files[1]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[2]['name']));
        self::assertContains('row1', $files[2]['tags']);
        self::assertContains('stage-last', $files[2]['tags']);
        self::assertGreaterThan(1000, $files[2]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[3]['name']));
        self::assertContains('row1', $files[3]['tags']);
        self::assertContains('stage-2', $files[3]['tags']);
        self::assertGreaterThan(1000, $files[3]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[3]['name']));
        self::assertContains('row1', $files[4]['tags']);
        self::assertContains('stage-1', $files[4]['tags']);
        self::assertGreaterThan(1000, $files[4]['sizeBytes']);

        self::assertEquals(0, strcasecmp('data.zip', $files[3]['name']));
        self::assertContains('row1', $files[5]['tags']);
        self::assertContains('stage-0', $files[5]['tags']);
        self::assertGreaterThan(1000, $files[5]['sizeBytes']);
    }
}