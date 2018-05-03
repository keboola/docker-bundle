<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Psr\Log\NullLogger;

class ImageCreatorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
        $this->encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $this->encryptorFactory->setComponentId('keboola.docker-demo');
    }

    public function testCreateImageDockerHub()
    {
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo-app',
                    'tag' => '1.1.6'
                ],
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
        $imageCreator = new ImageCreator($this->encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('keboola/docker-demo-app:1.1.6', $images[0]->getFullImageId());
    }

    public function testCreateImageDockerHubPrivate()
    {
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub-private',
                    'uri' => 'keboolaprivatetest/docker-demo-docker',
                    'repository' => [
                        '#password' => $this->encryptorFactory->getEncryptor()->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
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
        $imageCreator = new ImageCreator($this->encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('keboolaprivatetest/docker-demo-docker:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageQuay()
    {
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
        $imageCreator = new ImageCreator($this->encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageQuayPrivate()
    {
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
        $imageCreator = new ImageCreator($this->encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageProcessors()
    {
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
        $imageCreator = new ImageCreator($this->encryptorFactory->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(3, $images);
        $this->assertContains('keboola.processor-decompress', $images[0]->getFullImageId());
        $this->assertEquals('keboola/docker-demo-app:1.1.6', $images[1]->getFullImageId());
        $this->assertContains('keboola.processor-iconv', $images[2]->getFullImageId());
        $this->assertFalse($images[0]->isMain());
        $this->assertTrue($images[1]->isMain());
        $this->assertFalse($images[2]->isMain());
    }
}
