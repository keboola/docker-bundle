<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertStringStartsWith("KBC::ComponentSecure::", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setStackId(parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST));
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptInvalidJson()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": wtf
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertEquals("error", $response["status"]);
        $this->assertContains("Bad JSON format of request body", $response["message"]);
    }

    public function testEncryptEmptyValues()
    {
        $json = '{"#nested":{"emptyObject":{},"emptyArray":[]},"nested":{"emptyObject":{},"emptyArray":[]},"emptyObject":{},"emptyArray":[],"emptyScalar":null}';
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json
        );
        $this->assertEquals($json, $client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testEncryptEmptyArray()
    {
        $json = '[]';
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json
        );
        $this->assertEquals($json, $client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testEncryptProject()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertStringStartsWith("KBC::ProjectSecure::", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setProjectId('123');
        $encryptorFactory->setStackId(parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST));
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptConfiguration()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify&projectId=123&configId=123456789',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertStringStartsWith("KBC::ConfigSecure::", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setProjectId('123');
        $encryptorFactory->setConfigurationId('123456789');
        $encryptorFactory->setStackId(parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST));
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptConfigurationNoProject()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify&configId=123456789',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals("The configId parameter must be used together with projectId.", $response["message"]);
    }

    public function testEncryptJsonHeaderWithCharset()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json; charset=UTF-8'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertStringStartsWith("KBC::ComponentSecure::", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setStackId(parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST));
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptPlaintextHeaderWithCharset()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            'value'
        );
        $response = $client->getResponse()->getContent();
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertStringStartsWith("KBC::ComponentSecure::", $response);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setStackId(parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST));
        $this->assertEquals("value", $encryptorFactory->getEncryptor()->decrypt($response));
    }

    public function testEncryptInvalidHeader()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['CONTENT_TYPE' => 'someotherheader;'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Incorrect Content-Type.', $response['message']);
    }

    public function testEncryptWithoutComponent()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals("Component Id is required.", $response["message"]);
    }

    public function testEncryptInvalidParams()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=docker-config-encrypt-verify&projectId=123&nonExistentParameter=123456789',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode(), (string)$client->getResponse()->getContent());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals("Unknown parameter: 'nonExistentParameter'.", $response["message"]);
    }
}
