<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ActionController;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\ObjectEncryptor\Wrapper\ComponentWrapper;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

class ActionControllerTest extends WebTestCase
{
    /**
     * @var ContainerInterface
     */
    private static $container;

    public function setUp()
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();
    }

    public function tearDown()
    {
        parent::tearDown();
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        (new Process("sudo docker rmi -f $(sudo docker images -aq --filter \"label=com.keboola.docker.runner.origin=builder\")"))->run();
    }


    protected function getStorageServiceStubDummy($encrypt = false)
    {
        $flags = [];
        if ($encrypt) {
            $flags = ["encrypt"];
        }
        $storageServiceStub = $this->getMockBuilder("\\Keboola\\Syrup\\Service\\StorageApi\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($storageClientStub));

        // mock client to return image data
        $indexActionValue = [
            'components' => [
                0 => [
                    'id' => 'docker-dummy-test',
                    'type' => 'other',
                    'name' => 'Docker Config Dump',
                    'description' => 'Testing Docker',
                    'longDescription' => null,
                    'hasUI' => false,
                    'hasRun' => true,
                    'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                    'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                    'data' => [
                        'definition' => [
                            'type' => 'dockerhub',
                            'uri' => 'keboola/docker-dummy-test',
                        ],
                        'synchronous_actions' => ['test'],
                    ],
                    'flags' => $flags,
                    'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                ]
            ]
        ];

        $storageClientStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123", "features" => []]]));

        return $storageServiceStub;
    }

    protected function getStorageServiceStubDcaPython($defaultBucket = false)
    {
        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($storageClientStub));

        // mock client to return image data
        $indexActionValue = [
            'components' => [
                0 => [
                    'id' => 'dca-custom-science-python',
                    'type' => 'application',
                    'name' => 'Custom science Python',
                    'description' => 'Custom science Python',
                    'longDescription' => null,
                    'hasUI' => false,
                    'hasRun' => false,
                    'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/dca-custom-science-python-32-1.png',
                    'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/dca-custom-science-python-64-1.png',
                    'data' => [
                        'definition' => [
                            'type' => 'builder',
                            'uri' => 'keboola/docker-custom-python',
                            'tag' => 'latest',
                            'build_options' => [
                                'parent_type' => 'quayio',
                                'repository' => [
                                    'uri' => '',
                                    'type' => 'git',
                                ],
                                'commands' => [
                                    0 => 'git clone -b {{version}} --depth 1 {{repository}} /home/ || (echo "KBC::USER_ERR:Cannot access the Git repository {{repository}}, please verify its URL, credentials and version.KBC::USER_ERR" && exit 1)',
                                ],
                                'parameters' => [
                                    0 => [
                                        'name' => 'version',
                                        'type' => 'string',
                                    ],
                                    1 => [
                                        'name' => 'repository',
                                        'type' => 'string',
                                    ],
                                    2 => [
                                        'name' => 'username',
                                        'type' => 'string',
                                        'required' => false,
                                    ],
                                    3 => [
                                        'name' => '#password',
                                        'type' => 'string',
                                        'required' => false,
                                    ],
                                ],
                                'entry_point' => 'python /home/main.py',
                            ],
                        ],
                        'process_timeout' => 21600,
                        'default_bucket' => $defaultBucket,
                        'memory' => '8192m',
                        'configuration_format' => 'json',
                        'synchronous_actions' => ['test', 'run', 'timeout', 'invalidjson', 'noresponse', 'usererror', 'apperror', 'decrypt'],
                    ],
                    'flags' => ['encrypt'],
                    'uri' => 'https://syrup.keboola.com/docker/dca-custom-science-python',
                ]
            ]
        ];

        $storageClientStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123", "features" => []]]));
        $storageClientStub->expects($this->any())
            ->method("getRunId")
            ->will($this->returnValue(uniqid()));

        return $storageServiceStub;
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Component 'docker-dummy-test-invalid' not found
     */
    public function testNonExistingComponent()
    {
        $content = '
        {
            "configData": {
                "something": "else"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test-invalid",
            "action" => "somethingelse"
        ];
        $request = Request::create(
            "/docker/docker-dummy-test-invalid/action/somethingelse",
            "POST",
            $parameters,
            [],
            [],
            $server,
            $content
        );

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Action 'somethingelse' not found
     */
    public function testNonExistingAction()
    {
        $content = '
        {
            "configData": {
                "something": "else"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "action" => "somethingelse"
        ];
        $request = Request::create("/docker/docker-dummy-test/action/somethingelse", 'POST', $parameters, [], [], $server, $content);


        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Attribute 'configData' missing in request body
     */
    public function testConfigDataMissing()
    {
        $content = '
        {
            "config": {
                "something": "else"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "action" => "test"
        ];
        $request = Request::create("/docker/docker-dummy-test/action/test", 'POST', $parameters, [], [], $server, $content);


        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }


    public function prepareRequest($method, $parameters = null)
    {
        $content = '
        {
            "configData": {
                "parameters": ' . ($parameters ? json_encode($parameters) : '{}') . '
                ,
                "runtime": {
                    "repository": "https://github.com/keboola/docker-actions-test",
                    "version": "0.0.6"            
                }
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,
            'HTTP_Content-Type' => 'application/json'
        ];
        $parameters = [
            "component" => "dca-custom-science-python",
            "action" => $method
        ];
        return Request::create("/docker/dca-custom-science-python/action/{$method}", 'POST', $parameters, [], [], $server, $content);
    }

    public function testActionTest()
    {
        $request = $this->prepareRequest('test');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"test":"test"}', $response->getContent());
    }

    public function testActionDefaultBucket()
    {
        $request = $this->prepareRequest('test');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"test":"test"}', $response->getContent());
    }


    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Action 'run' not allowed
     */
    public function testActionRun()
    {
        $request = $this->prepareRequest('run');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }


    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessageRegExp /exceeded the timeout of 30 seconds/
     */
    public function testTimeout()
    {
        $request = $this->prepareRequest('timeout');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage user error
     */
    public function testUserException()
    {
        $request = $this->prepareRequest('usererror');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\ApplicationException
     * @expectedExceptionMessageRegExp /Application error/
     */
    public function testAppException()
    {
        $request = $this->prepareRequest('apperror');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage Decoding JSON response from component failed
     */
    public function testInvalidJSONResponse()
    {
        $request = $this->prepareRequest('invalidjson');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage No response from component
     */
    public function testNoResponse()
    {
        $request = $this->prepareRequest('noresponse');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    public function testDecryptSuccess()
    {
        $container = self::$container;

        /** @var $encryptor ObjectEncryptor */
        $encryptor = $container->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $encryptedPassword = $encryptor->encrypt('password');
        $request = $this->prepareRequest('decrypt', ["#password" => $encryptedPassword]);

        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"password":"password"}', $response->getContent());
    }

    public function testDecryptNewSuccess()
    {
        $container = self::$container;

        /** @var $encryptorFactory ObjectEncryptorFactory */
        $encryptorFactory = clone $container->get('docker_bundle.object_encryptor_factory');
        $encryptorFactory->setProjectId('123');
        $encryptorFactory->setComponentId('dca-custom-science-python');
        $encryptorFactory->setStackId(parse_url($container->getParameter('storage_api.url'), PHP_URL_HOST));
        $encryptedPassword = $encryptorFactory->getEncryptor()->encrypt('password', ComponentWrapper::class);
        $request = $this->prepareRequest('decrypt', ["#password" => $encryptedPassword]);

        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"password":"password"}', $response->getContent());
    }

    public function testUnencryptedSuccess()
    {
        $container = self::$container;

        $request = $this->prepareRequest('decrypt', ["#password" => 'password']);
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"password":"password"}', $response->getContent());
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage failed
     */
    public function testDecryptFailure()
    {
        $request = $this->prepareRequest('decrypt', ["#password" => "nesmysl"]);
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage failed
     */
    public function testDecryptMismatch()
    {
        $container = self::$container;

        /** @var ObjectEncryptor $encryptor */
        $encryptor = $container->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $encryptedPassword = $encryptor->encrypt('mismatch');
        $request = $this->prepareRequest('decrypt', ["#password" => $encryptedPassword]);

        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage Invalid cipher text for key #password
     */
    public function testActionDecryptError()
    {
        $container = self::$container;
        $request = $this->prepareRequest(
            'decrypt',
            ["#password" => "KBC::ComponentEncrypted==g2sGNtFXGNTIS6thisiswrongQ4zspYMcA=="]
        );

        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython());
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }
}
