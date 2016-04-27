<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ActionController;
use Keboola\Syrup\Exception\UserException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ActionControllerTest extends WebTestCase
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
                                'synchronous_actions' => ['test', 'timeout'],
                            ),
                            'flags' => $flags,
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        )
                )
        );

        $storageClientStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123"]]));

        return $storageServiceStub;
    }
    
    protected function getStorageServiceStubDcaPython()
    {
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
                        array (
                            'id' => 'dca-custom-science-python',
                            'type' => 'application',
                            'name' => 'Custom science Python',
                            'description' => 'Custom science Python',
                            'longDescription' => NULL,
                            'hasUI' => false,
                            'hasRun' => false,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/dca-custom-science-python-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/dca-custom-science-python-64-1.png',
                            'data' =>
                                array (
                                    'definition' =>
                                        array (
                                            'type' => 'builder',
                                            'uri' => 'quay.io/keboola/docker-custom-python:1.1.0',
                                            'build_options' =>
                                                array (
                                                    'repository' =>
                                                        array (
                                                            'uri' => '',
                                                            'type' => 'git',
                                                        ),
                                                    'commands' =>
                                                        array (
                                                            0 => 'git clone -b {{version}} --depth 1 {{repository}} /home/ || (echo "KBC::USER_ERR:Cannot access the Git repository {{repository}}, please verify its URL, credentials and version.KBC::USER_ERR" && exit 1)',
                                                        ),
                                                    'parameters' =>
                                                        array (
                                                            0 =>
                                                                array (
                                                                    'name' => 'version',
                                                                    'type' => 'string',
                                                                ),
                                                            1 =>
                                                                array (
                                                                    'name' => 'repository',
                                                                    'type' => 'string',
                                                                ),
                                                            2 =>
                                                                array (
                                                                    'name' => 'username',
                                                                    'type' => 'string',
                                                                    'required' => false,
                                                                ),
                                                            3 =>
                                                                array (
                                                                    'name' => '#password',
                                                                    'type' => 'string',
                                                                    'required' => false,
                                                                ),
                                                        ),
                                                    'entry_point' => 'python /home/main.py',
                                                ),
                                        ),
                                    'process_timeout' => 21600,
                                    'memory' => '8192m',
                                    'configuration_format' => 'json',
                                    'synchronous_actions' => ['test', 'timeout', 'json', 'invalidjson', 'noresponse', 'usererror', 'apperror', 'encrypt'],
                                ),
                            'flags' => ['encrypt'],
                            'uri' => 'https://syrup.keboola.com/docker/dca-custom-science-python',
                        )
                )
        );

        $storageClientStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123"]]));

        return $storageServiceStub;
    }    

    public function testNonExistingComponent()
    {
        $content = '
        {
            "something": "else"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test-invalid",
            "action" => "somethingelse"
        ];
        $request = Request::create("/docker/docker-dummy-test-invalid/action/somethingelse", 'POST', $parameters, [], [],
            $server, $content);


        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(404, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("error", $responseData["status"]);
        $this->assertEquals("Component 'docker-dummy-test-invalid' not found.", $responseData["message"]);
    }
    
    
    public function testNonExistingAction()
    {
        $content = '
        {
            "something": "else"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "action" => "somethingelse"
        ];
        $request = Request::create("/docker/docker-dummy-test/action/somethingelse", 'POST', $parameters, [], [],
            $server, $content);


        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(404, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("error", $responseData["status"]);
        $this->assertEquals("Action 'somethingelse' not found.", $responseData["message"]);
    }


    public function testActionTest()
    {
        $content = '
        {
            "parameters": {
            },
            "runtime": {
                "repository": "https://github.com/keboola/docker-actions-test",
                "version": "0.0.4"            
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "dca-custom-science-python",
            "action" => "test"
        ];
        $request = Request::create("/docker/dca-custom-science-python/action/test", 'POST', $parameters, [], [], $server,
            $content);

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("ok", $responseData["status"]);
        $this->assertEquals("test", $responseData["payload"]);
    }

    // TODO
    public function testJSONResponse() {
        $this->fail("not implemented");
    }

    // TODO
    public function testTimeout() {
        $this->fail("not implemented");

    }

    // TODO error
    public function testUserException() {
        $this->fail("not implemented");
    }

    // TODO error
    public function testAppException()
    {
        $this->fail("not implemented");
    }

    // TODO invalid JSON response
    public function testInvalidJSONRepsonse() {
        $this->fail("not implemented");
    }

    // TODO decrypt params
    public function testDecrypt() {
        $this->fail("not implemented");
    }
}
