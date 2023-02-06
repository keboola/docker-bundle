<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use PHPUnit\Framework\TestCase;

class SharedCodeRowConfigurationTest extends TestCase
{
    public function testSharedCodeRowConfiguration()
    {
        $configuration = [
            'configuration' => [
                'variables_id' => 123,
                'code_content' => ['some {{script}} line 1', '{{some}} script line 2 '],
            ],
        ];
        $result = (new Configuration\SharedCodeRow())->parse($configuration);
        self::assertEquals($configuration['configuration'], $result);
    }

    public function testSharedCodeRowConfigurationStringToArray()
    {
        $expectedResult = [
            'variables_id' => 123,
            'code_content' => ['some script'],
        ];
        $config = (new Configuration\SharedCodeRow())->parse([
            'configuration' => [
                'variables_id' => 123,
                'code_content' => 'some script',
            ],
        ]);
        self::assertEquals($expectedResult, $config);
    }
}
