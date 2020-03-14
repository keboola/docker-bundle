<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class VariableResolverTest extends TestCase
{
    public function testResolveVariables()
    {
        $client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $variableResolver = new VariableResolver($client);
        $jobDefinition = new JobDefinition(['foo' => 'bar'], new Component(['a' => 'b']), '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables([$jobDefinition], '123', [])[0];
        self::assertEquals(
            [],
            $newJobDefinition->getConfiguration()
        );
    }
}
