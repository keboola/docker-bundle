<?php

namespace Keboola\DockerBundle\Tests\JobExecutorTest;

use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EncryptionTests extends KernelTestCase
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
        self::bootKernel();
    }

    private function getJobExecutor(&$encryptor, &$ecWrapper, &$ecpWrapper, $handler, $indexActionValue)
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

        $encryptor = new ObjectEncryptor();
        $baseWrapper = new BaseWrapper(hash('sha256', uniqid()));
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('docker-dummy-component');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('docker-dummy-component');
        $ecpWrapper->setProjectId($tokenData["owner"]["id"]);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);
        $encryptor->pushWrapper($baseWrapper);

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        $runner = new Runner(
            $this->temp,
            $encryptor,
            $storageServiceStub,
            $loggersServiceStub
        );

        // mock components
        $configData = [
            "configuration" => [
                "parameters" => [
                    "key1" => "value1",
                    "#key2" => $encryptor->encrypt("value2"),
                    "#key3" => $encryptor->encrypt("value3", ComponentWrapper::class),
                    "#key4" => $encryptor->encrypt("value4", ComponentProjectWrapper::class),
                ]
            ],
            "state" => []
        ];
        $componentsStub = $this->getMockBuilder(Components::class)
            ->disableOriginalConstructor()
            ->getMock();
        $componentsStub->expects($this->once())
            ->method("getConfiguration")
            ->with("docker-dummy-component", 1)
            ->will($this->returnValue($configData));

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
            $encryptor,
            $componentsServiceStub,
            $ecWrapper,
            $ecpWrapper
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
                'config' => 1
            ]
        ];

        // fake image data
        $indexActionValue = $this->getComponentDefinition();
        $indexActionValue['components']['0']['flags'] = [];

        $handler = new TestHandler();
        /** @var ComponentWrapper $ecWrapper */
        /** @var ComponentProjectWrapper $ecpWrapper */
        /** @var ObjectEncryptor $encryptor */
        $jobExecutor = $this->getJobExecutor($encryptor, $ecWrapper, $ecpWrapper, $handler, $indexActionValue);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertEquals("KBC::Encrypted==", substr($config["parameters"]["#key2"], 0, 16));
        $this->assertEquals(
            $ecWrapper->getPrefix(),
            substr($config["parameters"]["#key3"], 0, strlen($ecWrapper->getPrefix()))
        );
        $this->assertEquals(
            $ecpWrapper->getPrefix(),
            substr($config["parameters"]["#key4"], 0, strlen($ecpWrapper->getPrefix()))
        );
    }

    public function testStoredConfigDecryptEncryptComponent()
    {
        $data = [
            'params' => [
                'component' => 'docker-dummy-component',
                'mode' => 'run',
                'config' => 1
            ]
        ];

        // fake image data
        $indexActionValue = $this->getComponentDefinition();
        $indexActionValue['components']['0']['flags'] = ['encrypt'];

        $handler = new TestHandler();
        /** @var ComponentWrapper $ecWrapper */
        /** @var ComponentProjectWrapper $ecpWrapper */
        /** @var ObjectEncryptor $encryptor */
        $jobExecutor = $this->getJobExecutor($encryptor, $ecWrapper, $ecpWrapper, $handler, $indexActionValue);
        $job = new Job($encryptor, $data);
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
}
