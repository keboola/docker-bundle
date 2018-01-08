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
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
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
use Symfony\Component\Filesystem\Filesystem;

class JobExecutorInlineConfigWithConfigIdTest extends KernelTestCase
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
            ->method('getClient')
            ->will($this->returnValue($this->client))
        ;
        $storageServiceStub->expects($this->any())
            ->method('getTokenData')
            ->will($this->returnValue($tokenData))
        ;

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        if ($handler) {
            $log->pushHandler($handler);
        }
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $loggersServiceStub->expects($this->any())
            ->method('getLog')
            ->will($this->returnValue($log))
        ;
        $loggersServiceStub->expects($this->any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger))
        ;

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $encryptor = new ObjectEncryptor();
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper->setProjectId($tokenData['owner']['id']);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        /** @var JobMapper $jobMapperStub */
        $runner = new Runner(
            $encryptor,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapperStub,
            'dummy',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
        $componentsService = new ComponentsService($storageServiceStub);

        $jobExecutorMock = $this->getMockBuilder(Executor::class)
            ->setMethods(['getComponent'])
            ->setConstructorArgs(
                [
                    $loggersServiceStub->getLog(),
                    $runner,
                    $encryptor,
                    $componentsService,
                    $ecWrapper,
                    $ecpWrapper,
                ]
            )
            ->getMock();

        $componentDefinition = array(
            'id' => 'keboola.r-transformation',
            'type' => 'other',
            'name' => 'R Transformation',
            'description' => 'Backend for R Transformations',
            'longDescription' => null,
            'hasUI' => false,
            'hasRun' => false,
            'ico32' => 'https://assets-cdn.keboola.com/developer-portal/icons/default-32.png',
            'ico64' => 'https://assets-cdn.keboola.com/developer-portal/icons/default-64.png',
            'data' =>
                array(
                    'definition' =>
                        array(
                            'type' => 'aws-ecr',
                            'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation',
                            'tag' => '1.2.4',
                        ),
                    'vendor' =>
                        array(
                            'contact' =>
                                array(
                                    0 => 'Keboola',
                                    1 => 'Křižíkova 488/115, Praha, CZ',
                                    2 => 'support@keboola.com',
                                ),
                        ),
                    'configuration_format' => 'json',
                    'network' => 'bridge',
                    'memory' => '8192m',
                    'process_timeout' => 21600,
                    'forward_token' => false,
                    'forward_token_details' => false,
                    'default_bucket' => true,
                    'default_bucket_stage' => 'out',
                    'staging_storage' =>
                        array(
                            'input' => 'local',
                        ),
                ),
            'flags' =>
                array(
                    0 => 'excludeFromNewList',
                ),
            'configurationSchema' =>
                array(),
            'emptyConfiguration' =>
                array(),
            'uiOptions' =>
                array(),
            'configurationDescription' => null,
            'uri' => 'https://syrup.keboola.com/docker/keboola.r-transformation',
        );
        $jobExecutorMock
            ->expects($this->once())
            ->method('getComponent')
            ->will(
                $this->returnValue(
                    $componentDefinition
                )
            )
        ;

        $jobExecutorMock->setStorageApi($this->client);

        return $jobExecutorMock;
    }

    private function getJobParameters()
    {
        $data = [
            'params' => [
                'component' => 'keboola.r-transformation',
                'mode' => 'run',
                'config' => 'docker-test',
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

        return $data;
    }

    public function setUp()
    {
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

        $this->assertTrue($this->client->tableExists('out.c-keboola-r-transformation-docker-test.transpose'));
    }
}
