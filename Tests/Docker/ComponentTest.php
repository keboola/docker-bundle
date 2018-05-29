<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\Syrup\Exception\ApplicationException;
use PHPUnit\Framework\TestCase;

class ComponentTest extends TestCase
{
    public function testConfiguration()
    {
        $configuration = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'memory' => '128m',
                'process_timeout' => 7200,
                'forward_token' => true,
                'forward_token_details' => true,
                'default_bucket' => true,
            ],
        ];

        $component = new Component($configuration);
        self::assertEquals('128m', $component->getMemory());
        self::assertEquals(7200, $component->getProcessTimeout());
        self::assertEquals('standard', $component->getLoggerType());
        self::assertEquals('tcp', $component->getLoggerServerType());
        self::assertEquals(
            [
                100 => 'none',
                200 => 'normal',
                250 => 'normal',
                300 => 'normal',
                400 => 'normal',
                500 => 'camouflage',
                550 => 'camouflage',
                600 => 'camouflage',
            ],
            $component->getLoggerVerbosity()
        );
        self::assertEquals(true, $component->forwardToken());
        self::assertEquals(true, $component->forwardTokenDetails());
        self::assertEquals(true, $component->hasDefaultBucket());
    }

    public function testConfigurationDefaults()
    {
        $configuration = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
            ],
        ];

        $component = new Component($configuration);
        self::assertEquals('256m', $component->getMemory());
        self::assertEquals(3600, $component->getProcessTimeout());
        self::assertEquals('standard', $component->getLoggerType());
        self::assertEquals('tcp', $component->getLoggerServerType());
        self::assertEquals(
            [
                100 => 'none',
                200 => 'normal',
                250 => 'normal',
                300 => 'normal',
                400 => 'normal',
                500 => 'camouflage',
                550 => 'camouflage',
                600 => 'camouflage',
            ],
            $component->getLoggerVerbosity()
        );
        self::assertEquals(false, $component->forwardToken());
        self::assertEquals(false, $component->forwardTokenDetails());
        self::assertEquals(false, $component->hasDefaultBucket());
    }

    public function testInvalidDefinition()
    {
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('definition is empty');
        new Component([]);
    }

    public function testGetSanitizedBucketNameDot()
    {
        $component = [
            'id' => 'keboola.ex-generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($component);
        self::assertEquals('in.c-keboola-ex-generic-test', $component->getDefaultBucketName('test'));
    }

    public function testGetSanitizedBucketNameNoDot()
    {
        $component = [
            'id' => 'ex-generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($component);
        self::assertEquals('in.c-ex-generic-test', $component->getDefaultBucketName('test'));
    }

    public function testGetSanitizedBucketNameTwoDot()
    {
        $component = [
            'id' => 'keboola.ex.generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($component);
        self::assertEquals('in.c-keboola-ex-generic-test', $component->getDefaultBucketName('test'));
    }

    public function testRunAsRoot()
    {
        $componentData = [
            'id' => 'keboola.ex-generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
        ];
        $component = new Component($componentData);
        $this->assertFalse($component->runAsRoot());
    }


    public function testDoNotRunAsRoot()
    {
        $componentData = [
            'id' => 'keboola.ex-generic',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
            'features' => [
                'container-root-user'
            ]
        ];
        $component = new Component($componentData);
        $this->assertTrue($component->runAsRoot());
    }

    public function testInvalidRepository()
    {
        try {
            new Component([
                'data' => [
                    'definition' => [
                        'type' => 'builder',
                        'uri' => 'keboolaprivatetest/docker-demo-docker',
                        'build_options' => [
                            'parent_type' => 'dockerhub-private',
                            'repository' => [
                                'uri' => 'https://github.com/keboola/docker-demo-app',
                                'type' => 'fooBar',
                            ],
                            'commands' => [
                                'composer install'
                            ],
                            'entry_point' => 'php /home/run.php --data=/data',
                        ]
                    ],
                ],
            ]);
        } catch (ApplicationException $e) {
            self::assertContains('Invalid repository_type', $e->getMessage());
        }
    }
}
