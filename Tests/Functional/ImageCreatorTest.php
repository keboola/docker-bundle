<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Defuse\Crypto\Key;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ImageCreatorTest extends TestCase
{
    /**
     * @var Client
     */
    protected $client;

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
    }

    public function testCreateImageDockerHub()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo-app',
                    'tag' => '1.1.6'
                ],
                'cpu_shares' => 1024,
                'memory' => '64m',
                'configuration_format' => 'json',
            ]
        ]);

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(1, $images);
        self::assertTrue($images[0]->isMain());
        self::assertEquals('keboola/docker-demo-app:1.1.6', $images[0]->getFullImageId());
    }

    public function testCreateImageDockerHubPrivate()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub-private',
                    'uri' => 'keboolaprivatetest/docker-demo-docker',
                    'repository' => [
                        '#password' => $encryptorFactory->getEncryptor()->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                        'username' => DOCKERHUB_PRIVATE_USERNAME
                    ]
                ],
                'cpu_shares' => 1024,
                'memory' => '64m',
                'configuration_format' => 'json',
            ]
        ]);

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(1, $images);
        self::assertTrue($images[0]->isMain());
        self::assertEquals('keboolaprivatetest/docker-demo-docker:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageQuay()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app'
                ],
                'cpu_shares' => 1024,
                'memory' => '64m',
                'configuration_format' => 'json',
            ]
        ]);

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(1, $images);
        self::assertTrue($images[0]->isMain());
        self::assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageQuayPrivate()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app'
                ],
                'cpu_shares' => 1024,
                'memory' => '64m',
                'configuration_format' => 'json',
            ]
        ]);

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(1, $images);
        self::assertTrue($images[0]->isMain());
        self::assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageProcessors()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo-app',
                    'tag' => '1.1.6'
                ],
                'cpu_shares' => 1024,
                'memory' => '64m',
                'configuration_format' => 'json',
            ]
        ]);

        $config = [
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor-decompress',
                        ],
                    ],
                ],
                'after' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor-iconv',
                        ],
                        'parameters' => ['source_encoding' => 'CP1250']
                    ],
                ],
            ],
            'storage' => [],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(3, $images);
        self::assertContains('keboola.processor-decompress', $images[0]->getFullImageId());
        self::assertEquals('keboola/docker-demo-app:1.1.6', $images[1]->getFullImageId());
        self::assertContains('keboola.processor-iconv', $images[2]->getFullImageId());
        self::assertFalse($images[0]->isMain());
        self::assertTrue($images[1]->isMain());
        self::assertFalse($images[2]->isMain());
    }
}
