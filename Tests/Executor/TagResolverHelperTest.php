<?php

namespace Keboola\DockerBundle\Tests\Executor;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Job\TagResolverHelper;
use Keboola\DockerBundle\Tests\BaseExecutorTest;

class TagResolverHelperTest extends BaseExecutorTest
{
    /**
     * @dataProvider tagOverrideTestDataProvider
     */
    public function testTagOverride($storedConfigTag, $requestConfigTag, $expectedTag)
    {
        $requestData = [
            'tag' => $requestConfigTag,
        ];
        $storedConfig = [
            'runtime' => [
                'image_tag' => $storedConfigTag,
            ],
        ];
        $component = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
            ],
        ]);
        $newTag = TagResolverHelper::resolveComponentImageTag(
            $requestData,
            $storedConfig,
            $component
        );
        self::assertSame($expectedTag, $newTag);
    }

    /**
     * @return \Generator
     */
    public function tagOverrideTestDataProvider()
    {
        yield 'no override' => [
            'storedConfigTag' => null,
            'requestConfigTag' => null,
            'expectedTag' => 'master',
        ];

        yield 'stored config' => [
            'storedConfigTag' => '1.2.5',
            'requestConfigTag' => null,
            'expectedTag' => '1.2.5',
        ];

        yield 'request config' => [
            'storedConfigTag' => null,
            'requestConfigTag' => '1.2.6',
            'expectedTag' => '1.2.6',
        ];

        yield 'stored config + request config' => [
            'storedConfigTag' => '1.2.5',
            'requestConfigTag' => '1.2.6',
            'expectedTag' => '1.2.6',
        ];
    }
}
