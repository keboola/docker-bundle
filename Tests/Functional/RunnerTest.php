<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RunnerTest extends KernelTestCase
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var Temp
     */
    private $temp;

    protected function clearBuckets()
    {
        foreach (['in.c-docker-test', 'out.c-docker-test'] as $bucket) {
            try {
                $this->client->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client(['token' => STORAGE_API_TOKEN]);
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        $this->clearBuckets();

        // Create buckets
        $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker TestSuite');
        $this->client->createBucket('docker-test', Client::STAGE_OUT, 'Docker TestSuite');

        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['docker-bundle-test']);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file['id']);
        }

        self::bootKernel();
    }

    public function tearDown()
    {
        $this->clearBuckets();
        parent::tearDown();
    }

    private function getRunner($handler, &$encryptor = null)
    {
        $tokenInfo = $this->client->verifyToken();
        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method('getClient')
            ->will($this->returnValue($this->client));
        $storageServiceStub->expects($this->any())
            ->method('getTokenData')
            ->will($this->returnValue($tokenInfo));

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler($handler);
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects($this->any())
            ->method('getLog')
            ->will($this->returnValue($log));
        $loggersServiceStub->expects($this->any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger));

        $encryptor = new ObjectEncryptor();
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper->setProjectId($tokenInfo['owner']['id']);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);
        $encryptor->pushWrapper(new BaseWrapper(hash('sha256', uniqid())));

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        $runner = new Runner(
            $this->temp,
            $encryptor,
            $storageServiceStub,
            $loggersServiceStub
        );
        return $runner;
    }

    public function testRunnerPipeline()
    {
        $dataDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
        $this->client->uploadFile(
            $dataDir . 'texty.zip',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'pipeline'])
        );
        $this->client->uploadFile(
            $dataDir . 'radio.zip',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'pipeline'])
        );

        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [[
                        'tags' => ['pipeline']
                    ]]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'radio.csv',
                            'destination' => 'out.c-docker-pipeline.radio'
                        ],
                        [
                            'source' => 'texty.csv',
                            'destination' => 'out.c-docker-pipeline.texty'
                        ],
                    ]
                ]
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/radio.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/radio.csv", row.names = FALSE)',
                    'data <- read.csv(file = "/data/in/tables/texty.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/texty.csv", row.names = FALSE)',
                ]
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor.unzipper',
                        ],
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor.iconv',
                        ],
                        'parameters' => ['source_encoding' => 'CP1250']
                    ],
                ],
            ],
        ];

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'longDescription' => null,
            'hasUI' => false,
            'hasRun' => true,
            'ico32' => '',
            'ico64' => '',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/r-transformation',
                    'tag' => '0.0.14',
                ],
            ],
        ];

        $runner = $this->getRunner(new NullHandler());
        $runner->run(
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'run',
            '1234567'
        );

        $csvData = $this->client->exportTable('out.c-docker-pipeline.radio');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(9, count($data));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('text', $data[0]);
        $this->assertArrayHasKey('tag', $data[0]);
        $this->assertArrayHasKey('rev', $data[0]);
        $csvData = $this->client->exportTable('out.c-docker-pipeline.texty');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(4, count($data));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('title', $data[0]);
        $this->assertArrayHasKey('text', $data[0]);
        $this->assertArrayHasKey('tags', $data[0]);
    }








    /*
    // Create bucket
    if (!$this->client->bucketExists('in.c-docker-test')) {
        $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker Testsuite');
    }

    // Create table
    if (!$this->client->tableExists('in.c-docker-test.test')) {
        $csv = new CsvFile($this->temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->client->createTableAsync('in.c-docker-test', 'test', $csv);
        $this->client->setTableAttribute('in.c-docker-test.test', 'attr1', 'val1');
        unset($csv);
    }
    */



    public function testImageParametersDecrypt()
    {
        $configurationData = [
            'storage' => [],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4'
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptor);
        /** @var ObjectEncryptor $encryptor */
        $encrypted = $encryptor->encrypt('someString');

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'longDescription' => null,
            'hasUI' => false,
            'hasRun' => true,
            'ico32' => '',
            'ico64' => '',
            'data' => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-base-php56:0.0.2",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app.git",
                            "type" => "git"
                        ],
                        "commands" => [],
                        "entry_point" => "cat /data/config.json",
                    ],
                ],
                'configuration_format' => 'json',
                'image_parameters' => [
                    'foo' => 'bar',
                    'baz' => [
                        'lily' => 'pond'
                    ],
                    '#encrypted' => $encrypted
                ]
            ],
        ];

        $runner->run(
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertEquals('bar', $config['image_parameters']['foo']);
        $this->assertEquals('pond', $config['image_parameters']['baz']['lily']);
        $this->assertEquals('someString', $config['image_parameters']['#encrypted']);
    }

    public function testImageParametersNoDecrypt()
    {
        $configurationData = [
            'storage' => [],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4'
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptor);
        /** @var ObjectEncryptor $encryptor */
        $encrypted = $encryptor->encrypt('someString');

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'longDescription' => null,
            'hasUI' => false,
            'hasRun' => true,
            'ico32' => '',
            'ico64' => '',
            'data' => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "quay.io/keboola/docker-base-php56:0.0.2",
                    "build_options" => [
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app.git",
                            "type" => "git"
                        ],
                        "commands" => [],
                        "entry_point" => "cat /data/config.json",
                    ],
                ],
                'configuration_format' => 'json',
                'image_parameters' => [
                    'foo' => 'bar',
                    'baz' => [
                        'lily' => 'pond'
                    ],
                    '#encrypted' => $encrypted
                ]
            ],
        ];

        $runner->run(
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'dry-run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertEquals('bar', $config['image_parameters']['foo']);
        $this->assertEquals('pond', $config['image_parameters']['baz']['lily']);
        $this->assertEquals($encrypted, $config['image_parameters']['#encrypted']);
    }
}
