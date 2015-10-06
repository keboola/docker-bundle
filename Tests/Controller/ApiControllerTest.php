<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ApiController;
use Keboola\Syrup\Exception\UserException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ApiControllerTest extends WebTestCase
{
    /**
     * @var ContainerInterface
     */
    private static $container;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

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
            "component" => "docker-demo",
            "concealComponent" => true
        ];
        $request = Request::create("/docker/docker-demo/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($result["#key2"], 0, 16));
        $this->assertCount(2, $result);
    }
}
