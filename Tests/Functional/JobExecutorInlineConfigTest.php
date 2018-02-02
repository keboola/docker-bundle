<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

class JobExecutorInlineConfigTest extends KernelTestCase
{
    /**
     * @var Client
     */
    private $client;
    /**
     * @var Temp
     */
    private $temp;

    private function getRunner(&$encryptorFactory, $handler = null)
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
        if ($handler) {
            $log->pushHandler($handler);
        }
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
        $componentsService = new ComponentsService($storageServiceStub);
        return [$runner, $componentsService, $loggersServiceStub, $tokenData];
    }

    private function getJobExecutor(&$encryptorFactory, $handler = null)
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        list($runner, $componentsService, $loggersServiceStub, $tokenData) = $this->getRunner($encryptorFactory, $handler);
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

    private function getJobParameters()
    {
        $data = [
            'params' => [
                'component' => 'keboola.r-transformation',
                'mode' => 'run',
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

        return $data;
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
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $handler);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $csvData = $this->client->getTableDataPreview(
            'out.c-docker-test.transposed',
            [
                'limit' => 1000,
            ]
        );
        $data = Client::parseCsv($csvData);

        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);
        $this->assertFalse($handler->hasWarning('Overriding component tag with: \'1.1.1\''));
    }

    /**
     * @expectedExceptionMessage Unsupported row value
     * @expectedException \Keboola\Syrup\Exception\UserException
     */
    public function testRunInvalidRowId()
    {
        $handler = new TestHandler();
        $data = $data = [
            'params' => [
                'component' => 'docker-encrypt-verify',
                'mode' => 'run',
                'configData' => [
                    'storage' => [],
                    'parameters' => [],
                ],
                'row' => [1, 2, 3]
            ],
        ];
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $handler);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);
    }

    public function testRunOAuth()
    {
        $handler = new TestHandler();
        $data = $this->getJobParameters();
        $data['params']['configData']['authorization']['oauth_api']['id'] = '12345';
        $data['params']['configData']['storage'] = [];
        $data['params']['configData']['parameters']['script'] = [
            'configFile <- "/data/config.json"',
            'data <- readChar(configFile, file.info(configFile)$size)',
            'print(data)'
        ];
        $encryptorFactory = new ObjectEncryptorFactory(
            static::$kernel->getContainer()->getParameter('kms_key_id'),
            static::$kernel->getContainer()->getParameter('kms_key_region'),
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        list($runner, $componentsService, $loggersServiceStub, $tokenData) = $this->getRunner($encryptorFactory);
        /** @var LoggersService $loggersServiceStub */
        $loggersServiceStub->getContainerLog()->pushHandler($handler);
        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret'
            ]
        ];
        $encryptorFactory->setComponentId('keboola.r-transformation');
        $encryptorFactory->setProjectId($tokenData["owner"]["id"]);
        $credentialsEncrypted = $encryptorFactory->getEncryptor()->encrypt($credentials, ComponentProjectWrapper::class);

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        // inject mock OAuth client inside Runner
        $prop = new \ReflectionProperty($runner, 'oauthClient');
        $prop->setAccessible(true);
        $prop->setValue($runner, $oauthStub);

        $jobExecutor = new Executor(
            $loggersServiceStub->getLog(),
            $runner,
            $encryptorFactory,
            $componentsService,
            STORAGE_API_URL
        );
        $jobExecutor->setStorageApi($this->client);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $data = '';
        foreach ($handler->getRecords() as $record) {
            $data .= $record['message'];
        }
        $this->assertContains('superDummySecret', $data);
        $this->assertContains('anotherTopSecret', $data);
    }

    public function testRunTag()
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
        $data['params']['tag'] = '1.1.1';
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $handler);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $csvData = $this->client->getTableDataPreview(
            'out.c-docker-test.transposed',
            [
                'limit' => 1000,
            ]
        );
        $data = Client::parseCsv($csvData);

        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);
        $this->assertTrue($handler->hasWarning('Overriding component tag with: \'1.1.1\''));
    }

    public function testSandbox()
    {
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

        $data = $this->getJobParameters();
        $data['params']['mode'] = 'sandbox';
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory);
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
        $listOptions->setTags(['sandbox']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(500, $files[0]['sizeBytes']);
        $this->assertLessThan(4000, $files[0]['sizeBytes']);
    }

    public function testInput()
    {
        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = $this->getJobParameters();
        $data['params']['mode'] = 'input';
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory);
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
        $listOptions->setTags(['input']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(3800, $files[0]['sizeBytes']);
    }

    public function testDryRun()
    {
        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = $this->getJobParameters();
        $data['params']['mode'] = 'dry-run';

        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory);
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
        $listOptions->setTags(['dry-run']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(6100, $files[0]['sizeBytes']);
    }

    public function testIncrementalTags()
    {
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

        $data = $this->getJobParameters();
        $data['params']['configData']['storage'] = [
            'input' => [
                'files' => [
                    [
                        'query' => 'tags: toprocess AND NOT tags: downloaded',
                        'processed_tags' => [
                            'downloaded',
                            'experimental',
                        ],
                    ],
                ],
            ],
        ];
        $data['params']['configData']['parameters'] = [
            'script' => [
                "inDirectory <- '/data/in/files/'",
                "outDirectory <- '/data/out/files/'",
                "files <- list.files(inDirectory, pattern = '^[0-9]+_upload$', full.names = FALSE)",
                "for (file in files) {",
                "    fn <- paste0(outDirectory, file, '.csv');",
                "    file.copy(paste0(inDirectory, file), fn);",
                "    app\$writeFileManifest(fn, c('processed', 'docker-bundle-test'))",
                "}",
            ],
        ];
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
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
}
