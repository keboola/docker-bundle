<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EncryptTest extends WebTestCase
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
        $client = self::createClient();
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
        self::assertEquals(200, $client->getResponse()->getStatusCode());
        self::assertEquals("value1", $response["key1"]);
        self::assertStringStartsWith("KBC::Encrypted==", $response["#key2"]);
        $encryptor = self::$container->get("syrup.object_encryptor");
        self::assertEquals("value2", $encryptor->decrypt($response["#key2"]));
        self::assertCount(2, $response);
    }

    public function testEncryptEmptyValues()
    {
        $json = '{"#nested":{"emptyObject":{},"emptyArray":[]},"nested":{"emptyObject":{},"emptyArray":[]},"emptyObject":{},"emptyArray":[],"emptyScalar":null}';
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json
        );
        self::assertEquals(200, $client->getResponse()->getStatusCode());
        self::assertEquals($json, $client->getResponse()->getContent());
    }

    public function testEncryptComponent()
    {
        $client = self::createClient();
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
        self::assertEquals(200, $client->getResponse()->getStatusCode());
        self::assertEquals("value1", $response["key1"]);
        self::assertEquals("KBC::ComponentEncrypted==", substr($response["#key2"], 0, 25));
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        self::assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        self::assertCount(2, $response);
    }

    public function testEncryptComponentProject()
    {
        $client = self::createClient();
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
        self::assertEquals(200, $client->getResponse()->getStatusCode());
        self::assertEquals("value1", $response["key1"]);
        self::assertStringStartsWith("KBC::ComponentProjectEncrypted==", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setProjectId('123');
        self::assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        self::assertCount(2, $response);
    }


    public function testEncryptJsonHeaderWithCharset()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json; charset=UTF-8'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(200, $client->getResponse()->getStatusCode());
        self::assertEquals("value1", $response["key1"]);
        self::assertEquals("KBC::Encrypted==", substr($response["#key2"], 0, 16));
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        self::assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        self::assertCount(2, $response);
    }

    public function testEncryptPlaintextHeaderWithCharset()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            'value'
        );
        $response = $client->getResponse()->getContent();
        self::assertEquals(200, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith("KBC::Encrypted==", $response);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        self::assertEquals("value", $encryptorFactory->getEncryptor()->decrypt($response));
    }

    public function testEncryptInvalidHeader()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'someotherheader;'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(400, $client->getResponse()->getStatusCode());
        self::assertArrayHasKey('status', $response);
        self::assertEquals('error', $response['status']);
        self::assertArrayHasKey('message', $response);
        self::assertEquals('Incorrect Content-Type.', $response['message']);
    }

    public function testEncryptOnAComponentThatDoesNotHaveEncryptFlag()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/encrypt?componentId=keboola.r-transformation',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(400, $client->getResponse()->getStatusCode());
        self::assertEquals("error", $response["status"]);
        self::assertEquals(
            "This API call is only supported for components that use the 'encrypt' flag.",
            $response["message"]
        );
    }
}
