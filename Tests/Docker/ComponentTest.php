<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

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
            'dataTypesConfiguration' => [
                'dataTypesSupport' => 'hints',
            ],
            'processorConfiguration' => [
                'allowedProcessorPosition' => 'before',
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
            $component->getLoggerVerbosity(),
        );
        self::assertEquals(true, $component->forwardToken());
        self::assertEquals(true, $component->forwardTokenDetails());
        self::assertEquals(true, $component->hasDefaultBucket());
        self::assertSame('master', $component->getImageTag());
        self::assertSame('hints', $component->getDataTypesSupport());
        self::assertSame('before', $component->getAllowedProcessorPosition());
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
            $component->getLoggerVerbosity(),
        );
        self::assertEquals(false, $component->forwardToken());
        self::assertEquals(false, $component->forwardTokenDetails());
        self::assertEquals(false, $component->hasDefaultBucket());
        self::assertSame('none', $component->getDataTypesSupport());
        self::assertSame('any', $component->getAllowedProcessorPosition());
    }

    public function testInvalidComponentNoDefinition()
    {
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'Component definition is invalid. Verify the deployment setup and the repository settings in ' .
            'the Developer Portal. Detail: The child config "definition" under "component.data" must be configured.',
        );
        new Component([]);
    }

    public function testInvalidComponentEmptyDefinition()
    {
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'Component definition is invalid. Verify the deployment setup and the repository settings in the ' .
            'Developer Portal. Detail: The child config "definition" under "component.data" must be configured',
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
            'Developer Portal. Detail: The path "component.data.definition.uri" ' .
            'cannot contain an empty value, but got "".',
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
                'container-root-user',
                'dev-branch-configuration-unsafe',
                'dev-branch-job-blocked',
                'dev-mapping-allowed',
                'container-tcpkeepalive-60s-override',
            ],
        ];
        $component = new Component($componentData);
        self::assertTrue($component->runAsRoot());
        self::assertTrue($component->allowBranchMapping());
        self::assertTrue($component->blockBranchJobs());
        self::assertTrue($component->branchConfigurationsAreUnsafe());
        self::assertTrue($component->overrideKeepalive60s());
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
                'no-swap',
            ],
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
                'no-swap',
            ],
        ];
        $component = new Component($componentData);
        $component->setImageTag('1.2.3');
        self::assertSame('1.2.3', $component->getImageTag());
    }
}
