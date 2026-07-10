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

class RunnerStateLoggingTest extends TestCase
{
    /**
     * At job start the Runner logs a "Using configuration id" notice. The configuration state must NOT be
     * part of that line: it is already decrypted at this point, so #-prefixed secrets it holds would be
     * written in plaintext to the (unfiltered) application log channel and shipped to Datadog. See
     * AJDA-3007.
     */
    public function testStateIsNotLoggedInConfigurationLogLine(): void
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
        $loggersService->expects(self::any())->method('getLog')->willReturn($log);

        // Basic client is used only in the Runner constructor.
        $basicClient = $this->createMock(Client::class);
        $basicClient->expects(self::any())->method('getTokenString')->willReturn('dummy-token');
        $basicClient->expects(self::any())->method('getServiceUrl')->willReturn('https://oauth.example.com');

        // Halt runRow() right after the "Using configuration id" line so the test needs no Docker/network.
        $branchClient = $this->createMock(BranchAwareClient::class);
        $branchClient->expects(self::once())
            ->method('verifyToken')
            ->willThrowException(new RuntimeException('halt after log line'));

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->expects(self::any())->method('getBasicClient')->willReturn($basicClient);
        $clientWrapper->expects(self::any())->method('getBranchClient')->willReturn($branchClient);

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
        } catch (RuntimeException $e) {
            // expected: we deliberately halt execution right after the log line is emitted; asserting the
            // message keeps the test hermetic and fails loudly if a different failure path is reached
            self::assertSame('halt after log line', $e->getMessage());
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
            'state:',
            $record['message'],
            'The configuration state must not be logged (AJDA-3007).',
        );
        self::assertStringNotContainsString(
            $secret,
            $record['message'],
            'The decrypted state secret leaked into the configuration log line.',
        );
        // the useful, non-sensitive fields are still logged
        self::assertStringContainsString('version:v123', $record['message']);
    }
}
