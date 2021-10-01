<?php

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use PHPUnit\Framework\TestCase;

class SharedCodeRowConfigurationTest extends TestCase
{
    public function testSharedCodeRowConfiguration()
    {
        (new Configuration\SharedCodeRow())->parse([
            'configuration' => [
                'variables_id' => 123,
                'code_content' => ['some {{script}} line 1', '{{some}} script line 2 '],
            ],
        ]);
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
