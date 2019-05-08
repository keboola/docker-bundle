<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\ImageCreator;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\Client;
use Psr\Log\NullLogger;

class ImageCreatorTest extends BaseRunnerTest
{
    /**
     * @var Client
     */
    protected $client;
    
    public function setUp()
    {
        parent::setUp();
        $components = [
            [
                'id' => 'keboola.processor-decompress',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress',
                        'tag' => 'latest'
                    ],
                ],
            ],
            [
                'id' => 'keboola.processor-iconv',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv',
                        'tag' => 'latest'
                    ],
                ],
            ]
        ];
        $this->client = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->client->expects($this->any())
            ->method('indexAction')
            ->will($this->returnValue(['components' => $components]));
        $this->getEncryptorFactory()->setComponentId('keboola.docker-demo');
    }

    public function testCreateImageDockerHub()
    {
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo-app',
                    'tag' => '1.1.6',
                ],
            ],
        ]);

        $config = [
            'storage' => [],
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $imageCreator = new ImageCreator($this->getEncryptorFactory()->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(1, $images);
        self::assertTrue($images[0]->isMain());
        self::assertEquals('keboola/docker-demo-app:1.1.6', $images[0]->getFullImageId());
    }

    public function testCreateImageQuay()
    {
        $image = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
            ],
        ]);

        $config = [
            'storage' => [],
            'parameters' => [
                'foo' => 'bar'
            ],
        ];
        $imageCreator = new ImageCreator($this->getEncryptorFactory()->getEncryptor(), new NullLogger(), $this->client, $image, $config);
        $images = $imageCreator->prepareImages();
        self::assertCount(1, $images);
        self::assertTrue($images[0]->isMain());
        self::assertEquals('quay.io/keboola/docker-demo-app:latest', $images[0]->getFullImageId());
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
            ],
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
                        'parameters' => [
                            'source_encoding' => 'CP1250',
                        ],
                    ],
                ],
            ],
            'storage' => [],
            'parameters' => [
                'foo' => 'bar',
            ],
        ];
        $imageCreator = new ImageCreator($this->getEncryptorFactory()->getEncryptor(), new NullLogger(), $this->client, $image, $config);
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
