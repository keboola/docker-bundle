<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\ObjectEncryptor\ObjectEncryptor;
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
        $this->assertStringStartsWith("KBC::Encrypted==", $response["#key2"]);
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
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
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
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
        $this->assertStringStartsWith("KBC::ComponentProjectEncrypted==", $response["#key2"]);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setProjectId('123');
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
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
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $this->assertEquals("value2", $encryptorFactory->getEncryptor()->decrypt($response["#key2"]));
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
        $this->assertStringStartsWith("KBC::Encrypted==", $response);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get("docker_bundle.object_encryptor_factory");
        $this->assertEquals("value", $encryptorFactory->getEncryptor()->decrypt($response));
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

/*
<?php

namespace Keboola\ObjectEncryptor\Tests;

use Defuse\Crypto\Key;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\ObjectEncryptor\Wrapper\GenericWrapper;
use PHPUnit\Framework\TestCase;

class ObjectEncryptorMigrationTest extends TestCase
{
    /**
     * @var ObjectEncryptorFactory
     *
private $factory;

/**
 * @var string
 *
private $aesKey;

public function setUp()
{
    parent::setUp();
    $globalKey = Key::createNewRandomKey()->saveToAsciiSafeString();
    $stackKey = Key::createNewRandomKey()->saveToAsciiSafeString();
    $legacyKey = '1234567890123456';
    $this->aesKey = '123456789012345678901234567890ab';
    $this->factory = new ObjectEncryptorFactory($globalKey, $legacyKey, $this->aesKey, $stackKey);
    $this->factory->setStackId('my-stack');
    $this->factory->setComponentId('dummy-component');
    $this->factory->setConfigurationId('123456');
    $this->factory->setProjectId('123');
}

public function testEncryptorScalar()
{
    $encryptor = $this->factory->getEncryptor();
    $originalText = 'secret';
    $encrypted = $encryptor->encrypt($originalText);
    self::assertStringStartsWith('KBC::Encrypted==', $encrypted);
    self::assertEquals($originalText, $encryptor->decrypt($encrypted));
    $migrated = $encryptor->migrate($encrypted, GenericWrapper::class);
    self::assertStringStartsWith('KBC::Secure::', $migrated);
    self::assertEquals($originalText, $encryptor->decrypt($migrated));
}

public function testEncryptorStack()
{
    $encryptor = $this->factory->getEncryptor();
    $originalText = 'secret';
    $encrypted = $encryptor->encrypt($originalText, GenericWrapper::class);
    self::assertStringStartsWith('KBC::Secure::', $encrypted);
    self::assertEquals($originalText, $encryptor->decrypt($encrypted));
    $migrated = $encryptor->migrate($encrypted, GenericWrapper::class);
    self::assertStringStartsWith('KBC::Secure::', $migrated);
    self::assertEquals($originalText, $encryptor->decrypt($migrated));
}

public function testEncryptorNestedArray()
{
    $encryptor = $this->factory->getEncryptor();
    $array = [
        'key1' => 'value1',
        'key2' => [
            'nestedKey1' => 'value2',
            'nestedKey2' => [
                '#finalKey' => 'value3'
            ]
        ]
    ];
    $result = $encryptor->encrypt($array);
    self::assertEquals('value1', $result['key1']);
    self::assertEquals('value2', $result['key2']['nestedKey1']);
    self::assertStringStartsWith('KBC::Encrypted==', $result['key2']['nestedKey2']['#finalKey']);

    $decrypted = $encryptor->decrypt($result);
    self::assertEquals('value1', $decrypted['key1']);
    self::assertEquals('value2', $decrypted['key2']['nestedKey1']);
    self::assertEquals('value3', $decrypted['key2']['nestedKey2']['#finalKey']);

    $migrated = $encryptor->migrate($result, GenericWrapper::class);
    self::assertStringStartsWith('KBC::Secure::', $migrated['key2']['nestedKey2']['#finalKey']);
    $decrypted = $encryptor->decrypt($migrated);
    self::assertEquals('value1', $decrypted['key1']);
    self::assertEquals('value2', $decrypted['key2']['nestedKey1']);
    self::assertEquals('value3', $decrypted['key2']['nestedKey2']['#finalKey']);
}

public function testMixedCryptoWrappersDecryptArray()
{
    $encryptor = $this->factory->getEncryptor();
    $wrapper = new AnotherCryptoWrapper();
    $wrapper->setKey(md5(uniqid()));
    $encryptor->pushWrapper($wrapper);

    $array = [
        '#key1' => $encryptor->encrypt('value1'),
        '#key2' => $encryptor->encrypt('value2', AnotherCryptoWrapper::class)
    ];
    self::assertStringStartsWith('KBC::Encrypted==', $array['#key1']);
    self::assertStringStartsWith('KBC::AnotherCryptoWrapper==', $array['#key2']);
    $decrypted = $encryptor->decrypt($array);
    self::assertEquals('value1', $decrypted['#key1']);
    self::assertEquals('value2', $decrypted['#key2']);
    $migrated = $encryptor->migrate($array, GenericWrapper::class);

    self::assertStringStartsWith('KBC::Secure::', $migrated['#key1']);
    self::assertStringStartsWith('KBC::Secure::', $migrated['#key2']);
    $decrypted = $encryptor->decrypt($migrated);
    self::assertEquals('value1', $decrypted['#key1']);
    self::assertEquals('value2', $decrypted['#key2']);
}
}


    /**
     * @param mixed $data
     * @param string $wrapperName
     * @return mixed
     * @throws ApplicationException
     * @throws UserException
     *
public function migrate($data, $wrapperName = BaseWrapper::class)
{
    $decrypted = $this->decrypt($data);
    $encrypted = $this->encrypt($decrypted, $wrapperName);
    return $encrypted;
}

*/
