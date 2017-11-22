<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\ObjectEncryptor\ObjectEncryptor;
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

    public function testEncryptEmptyValues()
    {
        $json = '{"#nested":{"emptyObject":{},"emptyArray":[]},"nested":{"emptyObject":{},"emptyArray":[]},"emptyObject":{},"emptyArray":[],"emptyScalar":null}';
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals($json, $client->getResponse()->getContent());
    }


    public function testEncrypt()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/encrypt',
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
        $this->assertStringStartsWith("KBC::ComponentEncrypted==", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptJsonHeaderWithCharset()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json; charset=UTF-8'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertStringStartsWith("KBC::ComponentEncrypted==", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptPlaintextHeaderWithCharset()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            'value'
        );
        $response = $client->getResponse()->getContent();
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertStringStartsWith("KBC::ComponentEncrypted==", $response);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $this->assertEquals("value", $encryptorFactory->getEncryptor()->decrypt($response));
    }

    public function testEncryptInvalidHeader()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/docker-config-encrypt-verify/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'someotherheader;'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Incorrect Content-Type.', $response['message']);
    }

    public function testEncryptOnAComponentThatDoesNotHaveEncryptFlag()
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{
                "key1": "value1",
                "#key2": "value2"
            }'
        );

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals(
            "This API call is only supported for components that use the 'encrypt' flag.",
            $response["message"]
        );
    }

    public function testEncryptWithoutComponent()
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
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertStringStartsWith("KBC::Encrypted==", $response["#key2"]);
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$container->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $this->assertEquals("value2", $encryptor->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptWithoutComponentEmptyValues()
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
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals($json, $client->getResponse()->getContent());
    }
}
