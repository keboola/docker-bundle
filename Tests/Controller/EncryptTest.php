<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
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
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($response["#key2"], 0, 16));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptEmptyValues()
    {
        $json = '{"#nested":{"emptyObject":{},"emptyArray":[]},"nested":{"emptyObject":{},"emptyArray":[]},"emptyObject":{},"emptyArray":[],"emptyScalar":null}';
        $client = $this->createClient();
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

    public function testEncryptComponent()
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
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertEquals("KBC::ComponentEncrypted==", substr($response["#key2"], 0, 25));
        $cryptoWrapper = self::$container->get("syrup.encryption.component_wrapper");
        $cryptoWrapper->setComponentId('docker-config-encrypt-verify');
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper($cryptoWrapper);
        $this->assertEquals("value2", $encryptor->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptComponentProject()
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
        $this->assertEquals("KBC::ComponentProjectEncrypted==", substr($response["#key2"], 0, 32));
        $cryptoWrapper = self::$container->get("syrup.encryption.component_project_wrapper");
        $cryptoWrapper->setComponentId('docker-config-encrypt-verify');
        $cryptoWrapper->setProjectId('123');
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper($cryptoWrapper);
        $this->assertEquals("value2", $encryptor->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }


    public function testEncryptJsonHeaderWithCharset()
    {
        $client = $this->createClient();
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
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("value1", $response["key1"]);
        $this->assertEquals("KBC::Encrypted==", substr($response["#key2"], 0, 16));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value2", $encryptor->decrypt($response["#key2"]));
        $this->assertCount(2, $response);
    }

    public function testEncryptPlaintextHeaderWithCharset()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/encrypt',
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            'value'
        );
        $response = $client->getResponse()->getContent();
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals("KBC::Encrypted==", substr($response, 0, 16));
        $encryptor = self::$container->get("syrup.object_encryptor");
        $this->assertEquals("value", $encryptor->decrypt($response));
    }

    public function testEncryptInvalidHeader()
    {
        $client = $this->createClient();
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
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Incorrect Content-Type.', $response['message']);
    }

    public function testEncryptOnAComponentThatDoesNotHaveEncryptFlag()
    {
        $client = $this->createClient();
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
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertEquals("error", $response["status"]);
        $this->assertEquals(
            "This API call is only supported for components that use the 'encrypt' flag.",
            $response["message"]
        );
    }

}
