<?php

namespace Keboola\DockerBundle\Tests\JobExecutorTest;

use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\DockerBundle\Service\StorageApiService;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EncryptionTest extends KernelTestCase
{
    /**
     * @var Client
     */
    private $client;

    public function setUp()
    {
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        self::bootKernel();
    }

    private function getJobExecutor(&$encryptorFactory, $handler, $indexActionValue)
    {
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
        $containerLogger->pushHandler($handler);
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

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('docker-dummy-component');
        $encryptorFactory->setProjectId($tokenData["owner"]["id"]);

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

        // mock components
        $configData = [
            "id" => "1",
            "version" => "1",
            "configuration" => [
                "parameters" => [
                    "key1" => "value1",
                    "#key2" => $encryptorFactory->getEncryptor()->encrypt("value2"),
                    "#key3" => $encryptorFactory->getEncryptor()->encrypt("value3", ComponentWrapper::class),
                    "#key4" => $encryptorFactory->getEncryptor()->encrypt("value4", ComponentProjectWrapper::class),
                ]
            ],
            "rows" => [],
            "state" => []
        ];

        $configDataRows = [
            "id" => "1",
            "version" => "1",
            "configuration" => [
                "parameters" => [
                    "configKey1" => "value1",
                    "#configKey2" => $encryptorFactory->getEncryptor()->encrypt("value2"),
                    "#configKey3" => $encryptorFactory->getEncryptor()->encrypt("value3", ComponentWrapper::class),
                    "#configKey4" => $encryptorFactory->getEncryptor()->encrypt("value4", ComponentProjectWrapper::class),
                ]
            ],
            "rows" => [
                [
                    "id" => "row-1",
                    "version" => 1,
                    "isDisabled" => false,
                    "configuration" => [
                        "parameters" => [
                            "rowKey1" => "value1",
                            "#rowKey2" => $encryptorFactory->getEncryptor()->encrypt("value2"),
                            "#rowKey3" => $encryptorFactory->getEncryptor()->encrypt("value3", ComponentWrapper::class),
                            "#rowKey4" => $encryptorFactory->getEncryptor()->encrypt("value4", ComponentProjectWrapper::class),
                        ]
                    ],
                    "state" => []
                ]
            ],
            "state" => []
        ];
        $componentsStub = $this->getMockBuilder(Components::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsStub->expects($this->once())
            ->method("getConfiguration")
            ->will($this->returnValueMap([
                ["docker-dummy-component", "config", $configData],
                ["docker-dummy-component", "config-rows", $configDataRows]
            ]));

        $componentsServiceStub = $this->getMockBuilder(ComponentsService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsServiceStub->expects($this->once())
            ->method("getComponents")
            ->will($this->returnValue($componentsStub));

        /** @var ComponentsService $componentsServiceStub */
        $jobExecutor = new Executor(
            $loggersServiceStub->getLog(),
            $runner,
            $encryptorFactory,
            $componentsServiceStub,
            self::$kernel->getContainer()->getParameter('storage_api.url')
        );

        // mock client to return image data
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $sapiStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue($tokenData));
        /** @noinspection PhpParamsInspection */
        $jobExecutor->setStorageApi($sapiStub);
        return $jobExecutor;
    }

    private function getComponentDefinition()
    {
        $indexActionValue = [
            'components' => [
                0 => [
                    'id' => 'docker-dummy-component',
                    'type' => 'other',
                    'name' => 'Docker Config Dump',
                    'description' => 'Testing Docker',
                    'longDescription' => null,
                    'hasUI' => false,
                    'hasRun' => true,
                    'ico32' => '',
                    'ico64' => '',
                    'data' => [
                        "definition" => [
                            "type" => "builder",
                            "uri" => "keboola/docker-demo-app",
                            "tag" => "latest",
                            "build_options" => [
                                "parent_type" => "quayio",
                                "repository" => [
                                    "uri" => "https://github.com/keboola/docker-demo-app.git",
                                    "type" => "git"
                                ],
                                "commands" => [],
                                "entry_point" => "cat /data/config.json",
                            ],
                        ],
                        "configuration_format" => "json",
                    ],
                    'flags' => [],
                ]
            ]
        ];
        return $indexActionValue;
    }

    public function testStoredConfigDecryptNonEncryptComponent()
    {
        $data = [
            'params' => [
                'component' => 'docker-dummy-component',
                'mode' => 'run',
                'config' => 'config'
            ]
        ];

        // fake image data
        $indexActionValue = $this->getComponentDefinition();
        $indexActionValue['components']['0']['flags'] = [];

        $handler = new TestHandler();
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $handler, $indexActionValue);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertStringStartsWith("KBC::Encrypted==", $config["parameters"]["#key2"]);
        $this->assertStringStartsWith("KBC::ComponentEncrypted==", $config["parameters"]["#key3"]);
        $this->assertStringStartsWith("KBC::ComponentProjectEncrypted==", $config["parameters"]["#key4"]);
    }

    public function testStoredConfigDecryptEncryptComponent()
    {
        $data = [
            'params' => [
                'component' => 'docker-dummy-component',
                'mode' => 'run',
                'config' => 'config'
            ]
        ];

        // fake image data
        $indexActionValue = $this->getComponentDefinition();
        $indexActionValue['components']['0']['flags'] = ['encrypt'];

        $handler = new TestHandler();
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $handler, $indexActionValue);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertEquals("value2", $config["parameters"]["#key2"]);
        $this->assertEquals("value3", $config["parameters"]["#key3"]);
        $this->assertEquals("value4", $config["parameters"]["#key4"]);
    }

    public function testStoredConfigRowDecryptEncryptComponent()
    {
        $data = [
            'params' => [
                'component' => 'docker-dummy-component',
                'mode' => 'run',
                'config' => 'config-rows'
            ]
        ];

        // fake image data
        $indexActionValue = $this->getComponentDefinition();
        $indexActionValue['components']['0']['flags'] = ['encrypt'];

        $handler = new TestHandler();
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $jobExecutor = $this->getJobExecutor($encryptorFactory, $handler, $indexActionValue);
        $job = new Job($encryptorFactory->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertEquals("value2", $config["parameters"]["#configKey2"]);
        $this->assertEquals("value3", $config["parameters"]["#configKey3"]);
        $this->assertEquals("value4", $config["parameters"]["#configKey4"]);
        $this->assertEquals("value2", $config["parameters"]["#rowKey2"]);
        $this->assertEquals("value3", $config["parameters"]["#rowKey3"]);
        $this->assertEquals("value4", $config["parameters"]["#rowKey4"]);
    }
}
