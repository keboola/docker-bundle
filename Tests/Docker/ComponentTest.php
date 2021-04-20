<?php

namespace Keboola\DockerBundle\Tests\Docker;

use InvalidArgumentException;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\ApplicationException;
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
        self::assertSame('master', $component->getImageTag());
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

    public function testInvalidComponentNoDefinition()
    {
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'Component definition is invalid. Verify the deployment setup and the repository settings in ' .
            'the Developer Portal. Detail: The child node "definition" at path "component" must be configured.'
        );
        new Component([]);
    }

    public function testInvalidComponentEmptyDefinition()
    {
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'Component definition is invalid. Verify the deployment setup and the repository settings in the ' .
            'Developer Portal. Detail: The child node "definition" at path "component" must be configured'
        );
        new Component([
            'data' => [
            ],
        ]);
    }

    public function testInvalidComponentEmptyUri()
    {
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'Component definition is invalid. Verify the deployment setup and the repository settings in the ' .
            'Developer Portal. Detail: The path "component.definition.uri" cannot contain an empty value, but got "".'
        );
        new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '',
                ],
            ],
        ]);
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

    public function testFlagsOff()
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
        self::assertFalse($component->runAsRoot());
        self::assertFalse($component->allowBranchMapping());
        self::assertFalse($component->blockBranchJobs());
        self::assertFalse($component->branchConfigurationsAreUnsafe());
    }

    public function testFlagsOn()
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
                'container-root-user', 'dev-branch-configuration-unsafe', 'dev-branch-job-blocked', 'dev-mapping-allowed',
            ],
        ];
        $component = new Component($componentData);
        self::assertTrue($component->runAsRoot());
        self::assertTrue($component->allowBranchMapping());
        self::assertTrue($component->blockBranchJobs());
        self::assertTrue($component->branchConfigurationsAreUnsafe());
    }

    public function testInvalidRepository()
    {
        try {
            new Component([
                'data' => [
                    'definition' => [
                        'type' => 'builder',
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/docker-demo',
                        'build_options' => [
                            'parent_type' => 'aws-ecr',
                            'repository' => [
                                'uri' => 'https://github.com/keboola/docker-demo-app',
                                'type' => 'fooBar',
                            ],
                            'commands' => [
                                'composer install',
                            ],
                            'entry_point' => 'php /home/run.php --data=/data',
                        ],
                    ],
                ],
            ]);
        } catch (ApplicationException $e) {
            self::assertContains('Invalid repository_type', $e->getMessage());
        }
    }

    public function testHasSwap()
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
        self::assertFalse($component->hasNoSwap());
    }

    public function testHasNoSwap()
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
                'no-swap'
            ]
        ];
        $component = new Component($componentData);
        self::assertTrue($component->hasNoSwap());
    }

    public function testSetTag()
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
                'no-swap'
            ]
        ];
        $component = new Component($componentData);
        $component->setImageTag('1.2.3');
        self::assertSame('1.2.3', $component->getImageTag());
    }

    public function testSetTagInvalid()
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
                'no-swap'
            ]
        ];
        $component = new Component($componentData);
        self::expectExceptionMessage('Argument $tag is expected to be a string, object given');
        self::expectException(InvalidArgumentException::class);
        $component->setImageTag(new \stdClass());
    }
}
