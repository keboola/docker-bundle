<?php

namespace Keboola\DockerBundle\Tests;

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
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
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

    private function getRunner(&$encryptorFactory, $configuration)
    {
        $storageApiClient = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
                'userAgent' => 'docker-bundle',
            ]
        );
        $tokenData = $storageApiClient->verifyToken();

        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $storageServiceStub->expects($this->any())
            ->method("getClient")
            ->will($this->returnValue($this->client))
        ;
        $storageServiceStub->expects($this->any())
            ->method("getTokenData")
            ->will($this->returnValue($tokenData))
        ;

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger("null");
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $loggersServiceStub->expects($this->any())
            ->method("getLog")
            ->will($this->returnValue($log))
        ;
        $loggersServiceStub->expects($this->any())
            ->method("getContainerLog")
            ->will($this->returnValue($containerLogger))
        ;

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $componentsStub = $this->getMockBuilder(Components::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $componentsStub->expects(self::once())
            ->method("getConfiguration")
            ->with("keboola.r-transformation", "my-config")
            ->will($this->returnValue($configuration))
        ;

        $componentsServiceStub = $this->getMockBuilder(ComponentsService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $componentsServiceStub->expects($this->any())
            ->method("getComponents")
            ->will($this->returnValue($componentsStub))
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

    private function getJobExecutor(&$encryptorFactory, $configuration)
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        list($runner, $componentsService, $loggersServiceStub, $tokenData) = $this->getRunner($encryptorFactory, $configuration);
        $encryptorFactory->setComponentId('keboola.r-transformation');
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

    public function testDebugModeInline()
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
                'component' => 'keboola.r-transformation',
                'mode' => 'debug',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-docker-test.source',
                                    'destination' => 'transpose.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'transpose.csv',
                                    'destination' => 'out.c-docker-test.transposed',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
                        ],
                    ],
                ],
            ],
        ];

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, []);
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
        $this->assertEquals(2, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(3800, $files[0]['sizeBytes']);
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
                                        'destination' => 'transpose.csv',
                                    ],
                                ],
                            ],
                            'output' => [
                                'tables' => [
                                    [
                                        'source' => 'transpose.csv',
                                        'destination' => 'out.c-docker-test.transposed',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'script' => [
                                'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                                'tdata <- t(data[, !(names(data) %in% ("name"))])',
                                'colnames(tdata) <- data[["name"]]',
                                'tdata <- data.frame(column = rownames(tdata), tdata)',
                                'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
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
                                        'destination' => 'transpose.csv',
                                    ],
                                ],
                            ],
                            'output' => [
                                'tables' => [
                                    [
                                        'source' => 'transpose.csv',
                                        'destination' => 'out.c-docker-test.transposed-2',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'script' => [
                                'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                                'tdata <- t(data[, !(names(data) %in% ("name"))])',
                                'colnames(tdata) <- data[["name"]]',
                                'tdata <- data.frame(column = rownames(tdata), tdata)',
                                'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)',
                            ],
                        ],
                    ],
                ],
            ],
            'configuration' => [],
        ];
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
                'component' => 'keboola.r-transformation',
                'mode' => 'debug',
                'config' => 'my-config',
            ],
        ];

        /** @var ObjectEncryptorFactory $encryptorFactory */
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
        $this->assertEquals(4, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(2000, $files[0]['sizeBytes']);
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
                'component' => 'keboola.r-transformation',
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
        /** @var ObjectEncryptorFactory $encryptorFactory */
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
        $this->assertEquals(6, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(2000, $files[0]['sizeBytes']);
    }
}