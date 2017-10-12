<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JobExecutorStoredConfigMultipleRowsTest extends KernelTestCase
{

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Temp
     */
    private $temp;

    private function getJobExecutor(&$encryptor, $handler = null)
    {
        $storageApiClient = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $tokenData = $storageApiClient->verifyToken();

        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method("getClient")
            ->will($this->returnValue($this->client));
        $storageServiceStub->expects($this->any())
            ->method("getTokenData")
            ->will($this->returnValue($tokenData));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        if ($handler) {
            $log->pushHandler($handler);
        }
        $containerLogger = new ContainerLogger("null");
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects($this->any())
            ->method("getLog")
            ->will($this->returnValue($log));
        $loggersServiceStub->expects($this->any())
            ->method("getContainerLog")
            ->will($this->returnValue($containerLogger));

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $encryptor = new ObjectEncryptor();
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper->setProjectId($tokenData["owner"]["id"]);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        /** @var JobMapper $jobMapperStub */
        $runner = new Runner(
            $this->temp,
            $encryptor,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapperStub,
            "dummy",
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );

        $componentsStub = $this->getMockBuilder(Components::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsStub->expects($this->once())
            ->method("getConfiguration")
            ->with("keboola.r-transformation", "my-config")
            ->will($this->returnValue($this->getConfiguration()));

        $componentsServiceStub = $this->getMockBuilder(ComponentsService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsServiceStub->expects($this->any())
            ->method("getComponents")
            ->will($this->returnValue($componentsStub));

        $jobExecutor = new Executor(
            $loggersServiceStub->getLog(),
            $runner,
            $encryptor,
            $componentsServiceStub,
            $ecWrapper,
            $ecpWrapper
        );
        $jobExecutor->setStorageApi($this->client);
        return $jobExecutor;
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
                ],
                [
                    'id' => 'row2',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
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
                                    'destination' => 'out.c-docker-test.transposed-2'
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
            ],
            'configuration' => []
        ];
    }

    private function getJobParameters()
    {
        $data = [
            'params' => [
                'component' => 'keboola.r-transformation',
                'mode' => 'run',
                'config' => 'my-config'
            ]
        ];
        return $data;
    }

    public function setUp()
    {
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
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
        $options->setTags(["docker-bundle-test", "sandbox", "input", "dry-run"]);
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

    public function testRun()
    {
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

        $handler = new TestHandler();
        $data = $this->getJobParameters();
        $jobExecutor = $this->getJobExecutor($encryptor, $handler);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $csvData = $this->client->getTableDataPreview('out.c-docker-test.transposed', [
            'limit' => 1000,
        ]);
        $data = Client::parseCsv($csvData);

        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);

        $csvData = $this->client->getTableDataPreview('out.c-docker-test.transposed-2', [
            'limit' => 1000,
        ]);
        $data = Client::parseCsv($csvData);

        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);
    }
}