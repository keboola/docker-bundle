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

    public function testPrepare()
    {
        $content = '
        {
            "config": "dummy"
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [];
        $request = Request::create("/docker/prepare", 'POST', $parameters, [], [], $server, $content);
        self::$container->set('request', $request);
        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->prepareAction($request);
        $this->assertEquals(202, $response->getStatusCode());
    }


    public function testInvalidComponent()
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

    public function testInvalidBody2()
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
        try {
            $ctrl->preExecute($request);
            $ctrl->runAction($request);
            $this->fail("Invalid body should raise exception.");
        } catch (UserException $e) {
        }
    }
}
