<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ApiController;
use Keboola\Syrup\Exception\UserException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Syrup\StorageApi\StorageApiServiceTest;

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

    public function testRun()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-r"
        ];
        $request = Request::create("/docker/docker-r/run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->runAction($request);
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testSandbox()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [];
        $request = Request::create("/docker/sandbox", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->sandboxAction($request);
        $this->assertEquals(202, $response->getStatusCode());
    }


    public function testInput()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-r"
        ];
        $request = Request::create("/docker/docker-r/input", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->inputAction($request);
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testDryRun()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-r"
        ];
        $request = Request::create("/docker/docker-r/dry-run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->dryRunAction($request);
        $this->assertEquals(202, $response->getStatusCode());
    }


    public function testInvalidComponentInput()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "invalid-component"
        ];
        $request = Request::create("/docker/invalid-component/input", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        try {
            $ctrl->preExecute($request);
            $ctrl->runAction($request);
            $this->fail("Invalid component should raise exception.");
        } catch (UserException $e) {
        }
    }


    public function testInvalidComponentRun()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "invalid-component"
        ];
        $request = Request::create("/docker/invalid-component/run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        try {
            $ctrl->preExecute($request);
            $ctrl->runAction($request);
            $this->fail("Invalid component should raise exception.");
        } catch (UserException $e) {
        }
    }

    public function testInvalidComponentDryRun()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "invalid-component"
        ];
        $request = Request::create("/docker/invalid-component/dry-run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        try {
            $ctrl->preExecute($request);
            $ctrl->runAction($request);
            $this->fail("Invalid component should raise exception.");
        } catch (UserException $e) {
        }
    }

    public function testInvalidBody1()
    {
        $content = '
        {
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-r"
        ];
        $request = Request::create("/docker/docker-r/run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        try {
            $ctrl->preExecute($request);
            $ctrl->runAction($request);
            $this->fail("Invalid body should raise exception.");
        } catch (UserException $e) {
        }
    }

    public function testBodyOverload()
    {
        $content = '
        {
            "config": "dummy",
            "configData": {
                "foo": "bar"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-r"
        ];
        $request = Request::create("/docker/docker-r/run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->runAction($request);
        $response = $ctrl->dryRunAction($request);
        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testEncrypt()
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
        $request = Request::create("/docker/docker-dummy-test/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

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

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->once())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123"]]));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);

        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($result["#key2"], 0, 16));
        $encryptor = self::$container->get("syrup.job_object_encryptor");
        $cryptoWrapper = self::$container->get("syrup.job_crypto_wrapper");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);
    }

    public function testEncryptOnAComponentThatDoesNotHaveEncryptFlag()
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

        $request = Request::create("/docker/docker-dummy-test/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

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

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("error", $responseData["status"]);
        $this->assertEquals("This API call is only supported for components that use the 'encrypt' flag.", $responseData["message"]);
    }

    public function testEncryptWithoutComponent()
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
        ];

        $request = Request::create("/docker/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

        $storageServiceStub = $this->getMockBuilder("\\Keboola\\Syrup\\Service\\StorageApi\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($storageClientStub));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($result["#key2"], 0, 16));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);
    }

    public function testInputDisabledByEncrypt()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test"
        ];
        $request = Request::create("/docker/docker-dummy-test/input", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $container = self::$container;

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
                            'uri' => 'https://syrup.keboola.com/docker/docker-config-dump',
                        )
                )
        );

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->inputAction($request);
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("error", $responseData["status"]);
        $this->assertEquals("This API call is not supported for components that use the 'encrypt' flag.", $responseData["message"]);
    }

    public function testDryRunDisabledByEncrypt()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test"
        ];
        $request = Request::create("/docker/docker-dummy-test/dry-run", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $container = self::$container;

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
                            'uri' => 'https://syrup.keboola.com/docker/docker-config-dump',
                        )
                )
        );

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->dryRunAction($request);
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("error", $responseData["status"]);
        $this->assertEquals("This API call is not supported for components that use the 'encrypt' flag.", $responseData["message"]);
    }

    /**
     * @expectedException Keboola\Syrup\Exception\ApplicationException
     * @expectedExceptionMessage Application error: Decryption failed:
     */
    public function testEncryptGenericDecryptComponentSpecific()
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
        ];

        $request = Request::create("/docker/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

        $storageServiceStub = $this->getMockBuilder("\\Keboola\\Syrup\\Service\\StorageApi\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($storageClientStub));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());

        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($result["#key2"], 0, 16));

        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);

        $componentEncryptor = self::$container->get("syrup.job_object_encryptor");
        $componentEncryptor->decrypt($result["#key2"]);
    }

    /**
     * @expectedException Keboola\Syrup\Exception\ApplicationException
     * @expectedExceptionMessage Application error: Decryption failed:
     */
    public function testEncryptComponentSpecificDecryptGeneric()
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
        $request = Request::create("/docker/docker-dummy-test/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

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

        $storageClientStub->expects($this->atLeastOnce())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->once())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123"]]));

        $container->set("syrup.storage_api", $storageServiceStub);

        $ctrl->setContainer($container);

        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($result["#key2"], 0, 16));
        $encryptor = self::$container->get("syrup.job_object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);


        $genericEncryptor = self::$container->get("syrup.object_encryptor");
        $genericEncryptor->decrypt($result["#key2"]);
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
        $request = Request::create("/docker/docker-dummy-test/configs/1", 'PUT', $parameters, [], [], $server, null);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

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
            ->will($this->returnValue(["owner" => ["id" => "123"]]));

        $cryptoWrapper = $container->get("syrup.job_crypto_wrapper");
        $cryptoWrapper->setComponentId('docker-dummy-test');
        $cryptoWrapper->setProjectId('123');
        $encryptor = $container->get("syrup.job_object_encryptor");

        $storageClientStub->expects($this->once())
            ->method("apiPut")
            ->with("storage/components/docker-dummy-test/configs/1", $this->callback(function($body) use ($encryptor) {
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
        $request = Request::create("/docker/docker-dummy-test/configs/1", 'PUT', $parameters, [], [], $server, null);
        self::$container->set('request', $request);
        $ctrl = new ApiController();

        $container = self::$container;

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
            ->with("storage/components/docker-dummy-test/configs/1", $this->callback(function($body) {
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

}
