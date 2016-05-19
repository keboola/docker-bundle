<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\PublicController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class PublicControllerTest extends WebTestCase
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

    public function testEncrypt()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'CONTENT_TYPE' => 'application/json'

        ];
        $parameters = [
            "component" => "docker-config-encrypt-verify"
        ];
        $request = Request::create("/docker/docker-config-encrypt-verify/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::ComponentEncrypted==", substr($result["#key2"], 0, 25));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);
    }

    public function testEncryptJsonHeaderWithCharset()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'CONTENT_TYPE' => 'application/json; charset=UTF-8'

        ];
        $parameters = [
            "component" => "docker-config-encrypt-verify"
        ];
        $request = Request::create("/docker/docker-config-encrypt-verify/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::ComponentEncrypted==", substr($result["#key2"], 0, 25));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);
    }

    public function testEncryptPlaintextHeaderWithCharset()
    {
        $content = 'value';
        $server = [
            'CONTENT_TYPE' => 'text/plain; charset=UTF-8'

        ];
        $parameters = [
            "component" => "docker-config-encrypt-verify"
        ];
        $request = Request::create("/docker/docker-config-encrypt-verify/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = $response->getContent();
        $this->assertEquals("KBC::ComponentEncrypted==", substr($result, 0, 25));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value", $encryptor->decrypt($result));
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage Incorrect Content-Type header.
     */
    public function testEncryptInvalidHeader()
    {
        $content = 'value';
        $server = [
            'CONTENT_TYPE' => 'someotherheader;'

        ];
        $parameters = [
            "component" => "docker-config-encrypt-verify"
        ];
        $request = Request::create("/docker/docker-config-encrypt-verify/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->encryptAction($request);
    }

    public function testEncryptOnAComponentThatDoesNotHaveEncryptFlag()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'CONTENT_TYPE' => 'application/json'

        ];
        $parameters = [
            "component" => "docker-encrypt"
        ];

        $request = Request::create("/docker/docker-encrypt/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals("error", $responseData["status"]);
        $this->assertEquals(
            "This API call is only supported for components that use the 'encrypt' flag.",
            $responseData["message"]
        );
    }

    public function testEncryptWithoutComponent()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'CONTENT_TYPE' => 'application/json'

        ];
        $parameters = [
        ];

        $request = Request::create("/docker/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
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

    public function testEncryptGenericDecryptComponentSpecific()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'CONTENT_TYPE' => 'application/json'

        ];
        $parameters = [
        ];

        $request = Request::create("/docker/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
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

    public function testEncryptComponentSpecificDecryptGeneric()
    {
        $content = '
        {
            "key1": "value1",
            "#key2": "value2"
        }';
        $server = [
            'CONTENT_TYPE' => 'application/json'

        ];
        $parameters = [
            "component" => "docker-config-encrypt-verify"
        ];
        $request = Request::create("/docker/docker-config-encrypt-verify/encrypt", 'POST', $parameters, [], [], $server, $content);
        self::$container->get('request_stack')->push($request);
        $ctrl = new PublicController();

        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->encryptAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertEquals("value1", $result["key1"]);
        $this->assertEquals("KBC::ComponentEncrypted==", substr($result["#key2"], 0, 25));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($result["#key2"]));
        $this->assertCount(2, $result);
    }
}
