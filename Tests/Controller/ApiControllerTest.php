<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ApiController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ApiControllerTest extends WebTestCase
{
    /**
     * @var ContainerInterface
     */
    private static $container;

    public function setUp()
    {
        parent::setUp();

        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();
    }

    protected function getStorageServiceStub($encrypt = false)
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
            'components' =>
                [
                    0 =>
                        [
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
                                'definition' =>
                                    [
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ],
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

    public function testRun()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function testSandbox()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/sandbox',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }


    public function testInput()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/input',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function testDryRun()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/dry-run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function testInvalidComponentInput()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/invalid-component/input',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Component \'invalid-component\' not found.', $response['message']);
    }

    public function testInvalidComponentRun()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/invalid-component/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Component \'invalid-component\' not found.', $response['message']);
    }

    public function testInvalidComponentDryRun()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/invalid-component/dry-run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Component \'invalid-component\' not found.', $response['message']);
    }

    public function testInvalidBody1()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Specify \'config\' or \'configData\'.', $response['message']);
    }

    public function testBodyOverload()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{
                "config": "dummy",
                "configData": {
                    "foo": "bar"
                }
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
    }

    public function testEncryptProject()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,
            'CONTENT_TYPE' => 'application/json'
        ];
        $parameters = [
            "component" => "docker-dummy-test"
        ];
        $request = Request::create(
            "/docker/docker-dummy-test/configs/encrypt",
            'POST',
            $parameters,
            [],
            [],
            $server,
            $content
        );
        self::$container->get('request_stack')->push($request);
        $ctrl = new ApiController();
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStub(true));
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptConfigAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::ComponentProjectEncrypted==", substr($result["#key2"], 0, 32));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);
    }

    public function testInputDisabledByEncrypt()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/input',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            '{
                "config": "dummy"
             }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals(
            "This API call is not supported for components that use the 'encrypt' flag.",
            $response["message"]
        );
    }

    public function testDryRunDisabledByEncrypt()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/dry-run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            '{
                "config": "dummy"
             }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals(
            "This API call is not supported for components that use the 'encrypt' flag.",
            $response["message"]
        );
    }


    public function testSaveEncryptedConfig()
    {
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,

        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "configId" => 1,
            "configuration" => '{
                "parameters": {
                    "plain": "test",
                    "#encrypted": "test"
                }
            }'
        ];
        $container = self::$container;
        $request = Request::create("/docker/docker-dummy-test/configs/1", 'PUT', $parameters, [], [], $server, null);
        $container->get('request_stack')->push($request);
        $ctrl = new ApiController();

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
        $indexActionValue = array(
            'components' =>
                array(
                    0 =>
                        array(
                            'id' => 'docker-dummy-test',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array(
                                'definition' =>
                                    array(
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ),
                            ),
                            'flags' => array('encrypt'),
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        )
                )
        );

        $responseJson = '{"id":"1","name":"devel","description":"","created":"2015-10-15T05:28:49+0200","creatorToken":{"id":3800,"description":"ondrej.hlavacek@keboola.com"},"version":2,"changeDescription":null,"configuration":{"configData":{"parameters":{"plain":"test","#encrypted":"KBC::Encrypt==ABCDEFGH"}}},"state":{}}';

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->once())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123", "features" => []]]));

        $cryptoWrapper = $container->get("syrup.encryption.component_project_wrapper");
        $cryptoWrapper->setComponentId('docker-dummy-test');
        $cryptoWrapper->setProjectId('123');
        $encryptor = $container->get("syrup.object_encryptor");

        $storageClientStub->expects($this->once())
            ->method("apiPut")
            ->with("storage/components/docker-dummy-test/configs/1", $this->callback(function ($body) use ($encryptor) {
                $params = json_decode($body["configuration"], true);
                if ($encryptor->decrypt($params["parameters"]["#encrypted"]) == 'test') {
                    return true;
                }
                return false;
            }))
            ->will($this->returnValue(json_decode($responseJson, true)));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->saveConfigAction($request);
    }


    public function testSaveUnencryptedConfig()
    {
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,

        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "configId" => 1,
            "configuration" => '{
                "parameters": {
                    "plain": "test",
                    "#encrypted": "test"
                }
            }'
        ];
        $container = self::$container;
        $request = Request::create("/docker/docker-dummy-test/configs/1", 'PUT', $parameters, [], [], $server, null);
        $container->get('request_stack')->push($request);
        $ctrl = new ApiController();

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
        $indexActionValue = array(
            'components' =>
                array(
                    0 =>
                        array(
                            'id' => 'docker-dummy-test',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array(
                                'definition' =>
                                    array(
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ),
                            ),
                            'flags' => array(),
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        )
                )
        );

        $responseJson = '{"id":"1","name":"devel","description":"","created":"2015-10-15T05:28:49+0200","creatorToken":{"id":3800,"description":"ondrej.hlavacek@keboola.com"},"version":2,"changeDescription":null,"configuration":{"configData":{"parameters":{"plain":"test","#encrypted":"test"}}},"state":{}}';

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->once())
            ->method("apiPut")
            ->with("storage/components/docker-dummy-test/configs/1", $this->callback(function ($body) {
                $params = json_decode($body["configuration"], true);
                if (substr($params["parameters"]["#encrypted"], 0, 16) != 'KBC::Encrypted==') {
                    return true;
                }
                return false;
            }))
            ->will($this->returnValue(json_decode($responseJson, true)));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->saveConfigAction($request);
    }


    public function testSaveConfigWithEmptyKey()
    {
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,

        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "configId" => 1,
            "configuration" => '{
                "": "value"
            }'
        ];
        $container = self::$container;
        $request = Request::create("/docker/docker-dummy-test/configs/1", 'PUT', $parameters, [], [], $server, null);
        $container->get('request_stack')->push($request);
        $ctrl = new ApiController();

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
        $indexActionValue = array(
            'components' =>
                array(
                    0 =>
                        array(
                            'id' => 'docker-dummy-test',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array(
                                'definition' =>
                                    array(
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ),
                            ),
                            'flags' => array(),
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        )
                )
        );

        $responseJson = '{"id":"1","name":"devel","description":"","created":"2015-10-15T05:28:49+0200","creatorToken":{"id":3800,"description":"ondrej.hlavacek@keboola.com"},"version":2,"changeDescription":null,"configuration":{"configData":{"":"value"}},"state":{}}';

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->once())
            ->method("apiPut")
            ->with("storage/components/docker-dummy-test/configs/1", $this->callback(function ($body) {
                if ($body['configuration'] != '{"":"value"}') {
                    return false;
                }
                return true;
            }))
            ->will($this->returnValue(json_decode($responseJson, true)));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->saveConfigAction($request);
    }

    
    public function testSaveChangeDescription()
    {
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,

        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "configId" => 1,
            "configuration" => '{
                "parameters": {
                    "plain": "test",
                    "#encrypted": "test"
                }
            }',
            "changeDescription" => "added or removed something"
        ];
        $container = self::$container;
        $request = Request::create("/docker/docker-dummy-test/configs/1", 'PUT', $parameters, [], [], $server, null);
        $container->get('request_stack')->push($request);
        $ctrl = new ApiController();

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
        $indexActionValue = array(
            'components' =>
                array(
                    0 =>
                        array(
                            'id' => 'docker-dummy-test',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array(
                                'definition' =>
                                    array(
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ),
                            ),
                            'flags' => array(),
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        )
                )
        );

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->once())
            ->method("apiPut")
            ->with("storage/components/docker-dummy-test/configs/1", $this->callback(function ($body) {
                if (isset($body["changeDescription"])) {
                    return true;
                }
                return false;
            }));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->saveConfigAction($request);
    }
}
