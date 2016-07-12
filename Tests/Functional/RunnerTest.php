<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineCacheBundle\Tests\Functional\FileSystemCacheTest;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Configuration\Input\File;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

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

    public function setUp()
    {
        $this->client = new Client(["token" => STORAGE_API_TOKEN]);
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

        // Create buckets
        $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");

        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(["docker-bundle-test"]);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        self::bootKernel();
    }

    private function getRunner()
    {
        $tokenInfo = $this->client->verifyToken();
        $tokenData = $this->client->verifyToken();

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

        $encryptor = new ObjectEncryptor();
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);

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
        $id1 = $this->client->uploadFile(
            ROOT_PATH . DIRECTORY_SEPARATOR . "Tests" . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'texty.zip',
            (new FileUploadOptions())->setTags(["docker-bundle-test", "pipeline"])
        );
        $id2 = $this->client->uploadFile(
            ROOT_PATH . DIRECTORY_SEPARATOR . "Tests" . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'radio.zip',
            (new FileUploadOptions())->setTags(["docker-bundle-test", "pipeline"])
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
            ]
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
                "definition" => [
                    "type" => "quayio",
                    "uri" => "keboola/r-transformation",
                    "tag" => "0.0.14",
                ],
                "processors" => [
                    [
                        "definition" => [
                            "type" => "quayio",
                            "uri" => "keboola/processor-unziper",
                            "tag" => "1.0.0",
                        ],
                        "priority" => -2
                    ],
                    [
                        "definition" => [
                            "type" => "quayio",
                            "uri" => "keboola/processor-iconv",
                            "tag" => "1.0.0",
                        ],
                        "priority" => -1,
                        "parameters" => ['parameters.iconv.sourceEncoding' => 'KBC_PROCESSOR_SOURCE_ENCODING']
                    ]
                ],
            ],
        ];

        $runner = $this->getRunner();
        $runner->run(
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'run'
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
}
