<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApiBranch\ClientWrapper;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class RunnerStateRedactionTest extends TestCase
{
    /**
     * The Runner logs the (already decrypted) configuration state at job start. This test guards that
     * #-prefixed secrets in the state are redacted from that log line before it reaches the application
     * log channel (and thus Datadog). See AJDA-3007.
     */
    public function testStateSecretsAreRedactedFromConfigurationLogLine(): void
    {
        $secret = 'shpat-super-secret-token-value';
        $state = [
            'component' => [
                '#token' => $secret,
                'lastRun' => '2026-07-10',
            ],
        ];

        $componentData = [
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => 'dummy-uri',
                ],
            ],
        ];

        $testHandler = new TestHandler();
        $log = new Logger('test', [$testHandler]);

        $loggersService = $this->createMock(LoggersService::class);
        $loggersService->method('getLog')->willReturn($log);

        // Basic client is used only in the Runner constructor.
        $basicClient = $this->createMock(Client::class);
        $basicClient->method('getTokenString')->willReturn('dummy-token');
        $basicClient->method('getServiceUrl')->willReturn('https://oauth.example.com');

        // Halt runRow() right after the "Using configuration id" line so the test needs no Docker/network.
        $branchClient = $this->createMock(BranchAwareClient::class);
        $branchClient->method('verifyToken')->willThrowException(new RuntimeException('halt after log line'));

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($basicClient);
        $clientWrapper->method('getBranchClient')->willReturn($branchClient);

        $runner = new Runner(
            $this->createMock(ObjectEncryptor::class),
            $clientWrapper,
            $loggersService,
            new OutputFilter(10000),
            ['cpu_count' => 2],
            $this->createMock(ImageFactory::class),
        );

        $jobDefinition = new JobDefinition(
            [],
            new ComponentSpecification($componentData),
            // configId null => shouldStoreState() short-circuits without an API call
            null,
            'v123',
            $state,
        );

        $outputs = [];
        try {
            $runner->run(
                [$jobDefinition],
                'run',
                'run',
                '123',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
            self::fail('Expected the run to halt at verifyToken(), but no exception was thrown.');
        } catch (Throwable) {
            // expected: we deliberately halt execution right after the log line is emitted
        }

        $record = null;
        foreach ($testHandler->getRecords() as $currentRecord) {
            if (str_contains($currentRecord['message'], 'Using configuration id')) {
                $record = $currentRecord;
                break;
            }
        }

        self::assertNotNull($record, 'The "Using configuration id" log line was not emitted.');
        self::assertStringNotContainsString(
            $secret,
            $record['message'],
            'The decrypted state secret leaked into the configuration log line.',
        );
        self::assertStringContainsString('[hidden]', $record['message']);
    }
}
