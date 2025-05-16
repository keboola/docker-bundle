<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\CommonExceptions\UserExceptionInterface;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\DockerBundle\Tests\ReflectionPropertyAccessTestCase;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\StorageApi\BranchAwareClient as StorageApiClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Throwable;

class Runner2Test extends BaseRunnerTest
{
    use ReflectionPropertyAccessTestCase;

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        $client = new StorageApiClient(
            'default',
            [
                'url' => getenv('STORAGE_API_URL'),
                'token' => getenv('STORAGE_API_TOKEN'),
            ],
        );
        $transformationTestComponentId = 'keboola.python-transformation';
        $components = new Components($client);
        $configurations = $components->listComponentConfigurations(
            (new ListComponentConfigurationsOptions())
                ->setComponentId($transformationTestComponentId),
        );
        foreach ($configurations as $configuration) {
            try {
                $components->deleteConfiguration($transformationTestComponentId, $configuration['id']);
            } catch (Throwable) {
                // do nothing
            }
        }
    }

    public function testPermissionsFailedWithoutContainerRootUserFeature()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $config = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'mytable.csv.gz',
                            'destination' => 'in.c-runner-test.mytable',
                            'columns' => ['col1'],
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from subprocess import call',
                    'import os',
                    'os.makedirs("/data/out/tables/mytable.csv.gz")',
                    'call(["chmod", "000", "/data/out/tables/mytable.csv.gz"])',
                    'with open("/data/out/tables/mytable.csv.gz/part1", "w") as file:',
                    '   file.write("value1")',
                ],
            ],
        ];

        $runner = $this->getRunner();
        self::expectException(UserExceptionInterface::class);
        // touch: cannot touch '/data/out/tables/mytable.csv.gz/part1': Permission denied
        self::expectExceptionMessageMatches('/Permission denied/');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $config,
                [],
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testTimeoutFromComponent(): void
    {
        $configData = [
            'parameters' => [
                'operation' => 'sleep',
                'timeout' => 90,
            ],
        ];

        $componentData = [
            'id' => 'keboola.runner-config-test',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.runner-config-test',
                    'tag' => '1.2.1',
                ],
                'process_timeout' => 10,
            ],
        ];

        $jobDefinition = new JobDefinition(
            $configData,
            new ComponentSpecification($componentData),
            'runner-configuration',
            null,
            [],
            'row-1',
        );
        $runner = $this->getRunner();
        $outputs = [];

        $startTime = time();

        try {
            $runner->run(
                [$jobDefinition],
                'run',
                'run',
                '1234567',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
            self::fail('Expected exception');
        } catch (Throwable $e) {
            self::assertInstanceOf(UserExceptionInterface::class, $e);
            self::assertSame(
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                'Running developer-portal-v2/keboola.runner-config-test:1.2.1 container exceeded the timeout of 10 seconds.',
                $e->getMessage(),
            );

            $endTime = time();
            $executionTime = $endTime - $startTime;
            self::assertGreaterThanOrEqual(10, $executionTime);
            self::assertLessThan(60, $executionTime);
        }
    }

    public function testTimeoutFromConfig(): void
    {
        $configData = [
            'parameters' => [
                'operation' => 'sleep',
                'timeout' => 90,
            ],
            'runtime' => [
                'process_timeout' => 20, // should override component timeout
            ],
        ];

        $componentData = [
            'id' => 'keboola.runner-config-test',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.runner-config-test',
                    'tag' => '1.2.1',
                ],
                'process_timeout' => 10,
            ],
        ];

        $jobDefinition = new JobDefinition(
            $configData,
            new ComponentSpecification($componentData),
            'runner-configuration',
            null,
            [],
            'row-1',
        );
        $runner = $this->getRunner();
        $outputs = [];

        $startTime = time();

        try {
            $runner->run(
                [$jobDefinition],
                'run',
                'run',
                '1234567',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
            self::fail('Expected exception');
        } catch (Throwable $e) {
            self::assertInstanceOf(UserExceptionInterface::class, $e);
            self::assertSame(
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                'Running developer-portal-v2/keboola.runner-config-test:1.2.1 container exceeded the timeout of 20 seconds.',
                $e->getMessage(),
            );

            $endTime = time();
            $executionTime = $endTime - $startTime;
            self::assertGreaterThanOrEqual(20, $executionTime);
            self::assertLessThan(60, $executionTime);
        }
    }
}
