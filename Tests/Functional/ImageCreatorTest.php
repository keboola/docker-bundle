<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class ImageCreatorTest extends \PHPUnit_Framework_TestCase
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
    }

    public function testCreateImageDockerHub()
    {
        $encryptor = new ObjectEncryptor();
        $log = new Logger('null');
        $log->pushHandler(new NullHandler());

        $image = [
            'definition' => [
                'type' => 'dockerhub',
                'uri' => 'keboola/docker-demo-app',
                'tag' => '1.1.6'
            ],
            'cpu_shares' => 1024,
            'memory' => '64m',
            'configuration_format' => 'json',
        ];

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptor, $log, $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('keboola/docker-demo-app:1.1.6', $images[0]->getFullImageId());
    }

    public function testCreateImageDockerHubPrivate()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));
        $log = new Logger('null');
        $log->pushHandler(new NullHandler());

        $image = [
            'definition' => [
                'type' => 'dockerhub-private',
                'uri' => 'keboolaprivatetest/docker-demo-docker',
                'repository' => [
                    '#password' => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                    'username' => DOCKERHUB_PRIVATE_USERNAME
                ]
            ],
            'cpu_shares' => 1024,
            'memory' => '64m',
            'configuration_format' => 'json',
        ];

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptor, $log, $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('keboolaprivatetest/docker-demo-docker:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageQuay()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));
        $log = new Logger('null');
        $log->pushHandler(new NullHandler());

        $image = [
            'definition' => [
                'type' => 'quayio',
                'uri' => 'keboola/docker-demo-app'
            ],
            'cpu_shares' => 1024,
            'memory' => '64m',
            'configuration_format' => 'json',
        ];

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptor, $log, $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
    }

    public function testCreateImageQuayPrivate()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());

        $image = [
            'definition' => [
                'type' => 'quayio',
                'uri' => 'keboola/docker-demo-app'
            ],
            'cpu_shares' => 1024,
            'memory' => '64m',
            'configuration_format' => 'json',
        ];

        $config = [
            'storage' => [
            ],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($encryptor, $log, $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(1, $images);
        $this->assertTrue($images[0]->isMain());
        $this->assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
    }

    public function testInvalidDefinition()
    {
        $encryptor = new ObjectEncryptor();
        $log = new Logger('null');
        $log->pushHandler(new NullHandler());

        $imageCreator = new ImageCreator($encryptor, $log, $this->client, [], []);
        try {
            $imageCreator->prepareImages();
            $this->fail("Invalid image definition must fail.");
        } catch (ApplicationException $e) {
            $this->assertContains('definition is empty', $e->getMessage());
        }
    }

    public function testCreateImageProcessors()
    {
        $encryptor = new ObjectEncryptor();
        $log = new Logger('null');
        $log->pushHandler(new NullHandler());

        $image = [
            'definition' => [
                'type' => 'dockerhub',
                'uri' => 'keboola/docker-demo-app',
                'tag' => '1.1.6'
            ],
            'cpu_shares' => 1024,
            'memory' => '64m',
            'configuration_format' => 'json',
        ];

        $config = [
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor.unzipper',
                        ],
                    ],
                ],
                'after' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor.iconv',
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
        $imageCreator = new ImageCreator($encryptor, $log, $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        $this->assertCount(3, $images);
        $this->assertEquals('quay.io/keboola/processor-unziper:3.0.4', $images[0]->getFullImageId());
        $this->assertEquals('keboola/docker-demo-app:1.1.6', $images[1]->getFullImageId());
        $this->assertEquals('quay.io/keboola/processor-iconv:1.0.2', $images[2]->getFullImageId());
        $this->assertFalse($images[0]->isMain());
        $this->assertTrue($images[1]->isMain());
        $this->assertFalse($images[2]->isMain());
    }
}
