<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\CommonExceptions\ApplicationExceptionInterface;
use Keboola\CommonExceptions\UserExceptionInterface;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\DockerBundle\Tests\ReflectionPropertyAccessTestCase;
use Keboola\DockerBundle\Tests\TestUsageFile;
use Keboola\InputMapping\Table\Options\InputTableOptions;
use Keboola\InputMapping\Table\Result\Column;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Components\ListConfigurationRowsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApiBranch\Branch;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Temp\Temp;
use Monolog\Logger;
use ReflectionMethod;

class RunnerTest extends BaseRunnerTest
{
    use ReflectionPropertyAccessTestCase;

    private const RUNNER_TEST_FILES_TAG = 'docker-runner-test';

    private function clearBuckets(): void
    {
        $buckets = ['in.c-runner-test', 'out.c-runner-test', 'in.c-keboola-docker-demo-sync-runner-configuration'];
        foreach ($buckets as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true, 'async' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }
    }

    private function clearConfigurations(): void
    {
        $cmp = new Components($this->getClient());
        try {
            $cmp->deleteConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    private function createBuckets(): void
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('runner-test', Client::STAGE_IN, 'Docker TestSuite', 'snowflake');
        $this->getClient()->createBucket('runner-test', Client::STAGE_OUT, 'Docker TestSuite', 'snowflake');
    }

    private function clearFiles(): void
    {
        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags([self::RUNNER_TEST_FILES_TAG]);
        sleep(1);
        $files = $this->getClient()->listFiles($options);
        foreach ($files as $file) {
            $this->getClient()->deleteFile($file['id']);
        }
    }

    /**
     * Transform metadata into a key-value array
     * @param $metadata
     * @return array
     */
    private function getMetadataValues($metadata): array
    {
        $result = [];
        foreach ($metadata as $item) {
            $result[$item['provider']][$item['key']] = $item['value'];
        }
        return $result;
    }

    public function testGetOauthUrl(): void
    {
        $clientMock = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->setMethods(['verifyToken', 'getServiceUrl'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('verifyToken')
            ->willReturn([]);
        $clientMock
            ->method('getServiceUrl')
            ->withConsecutive(['oauth'], ['oauth'])
            ->willReturnOnConsecutiveCalls(
                'https://someurl',
                'https://someurl',
            );

        $clientWrapper = $this->createMock(ClientWrapper::class);
        // basicClient TODO: should be removed after https://keboola.atlassian.net/browse/SOX-368
        $clientWrapper->method('getBasicClient')->willReturn($clientMock);
        $clientWrapper->method('getDefaultBranch')->willReturn(
            new Branch((string) '123', 'default branch', true, null),
        );
        $runner = new Runner(
            $this->encryptor,
            $clientWrapper,
            $this->loggersServiceStub,
            new OutputFilter(10000),
            ['cpu_count' => 2],
            (int) self::getOptionalEnv('RUNNER_MIN_LOG_PORT'),
        );

        $method = new ReflectionMethod($runner, 'getOauthUrlV3');
        $method->setAccessible(true);
        $response = $method->invoke($runner);
        self::assertEquals($response, 'https://someurl');
    }

    public function testRunnerProcessors(): void
    {
        $fileTag = 'texty.csv.gz';

        $this->clearBuckets();
        $this->clearFiles();
        $components = [
            [
                'id' => 'keboola.processor-last-file',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-last-file',
                        'tag' => '0.3.0',
                    ],
                ],
            ],
            [
                'id' => 'keboola.processor-iconv',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv',
                        'tag' => '4.0.0',
                    ],
                ],
            ],
            [
                'id' => 'keboola.processor-move-files',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-move-files',
                        'tag' => 'v2.2.1',
                    ],
                ],
            ],
            [
                'id' => 'keboola.processor-decompress',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress',
                        'tag' => 'v4.1.0',
                    ],
                ],
            ],
        ];
        $clientMock = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->setMethods(['apiGet', 'getServiceUrl'])
            ->getMock();
        $clientMock
            ->method('getServiceUrl')
            ->withConsecutive(['sandboxes'], ['oauth'])
            ->willReturnOnConsecutiveCalls(
                'https://sandboxes.someurl',
                'https://someurl',
            );
        $clientMock
            ->method('apiGet')
            ->willReturnCallback(function ($url, $filename) use ($components) {
                if ($url === 'components/keboola.processor-last-file') {
                    return $components[0];
                } elseif ($url === 'components/keboola.processor-iconv') {
                    return $components[1];
                } elseif ($url === 'components/keboola.processor-move-files') {
                    return $components[2];
                } elseif ($url === 'components/keboola.processor-decompress') {
                    return $components[3];
                } else {
                    return $this->client->apiGet($url, $filename);
                }
            });

        $this->uploadTextTestFile($fileTag);

        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [
                        [
                            'tags' => [$fileTag],
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'texty.csv',
                            'destination' => 'out.c-runner-test.texty',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'data <- read.csv(file = "/data/in/tables/texty.csv.gz/texty.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/texty.csv", row.names = FALSE)',
                ],
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor-last-file',
                            'tag' => '0.3.1', // run with custom tag
                        ],
                        'parameters' => ['tag' => 'texty.csv.gz'],
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor-decompress',
                        ],
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor-move-files',
                        ],
                        'parameters' => ['direction' => 'tables'],
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor-iconv',
                        ],
                        'parameters' => ['source_encoding' => 'CP1250'],
                    ],
                ],
            ],
        ];
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation',
                    'tag' => '1.2.8',
                ],
            ],
        ];
        $this->setClientMock($clientMock);
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
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

        self::assertSame(
            'developer-portal-v2/keboola.processor-last-file:0.3.1',
            $outputs[0]->getImages()[0]['id'],
        );
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.processor-last-file@sha256:',
            $outputs[0]->getImages()[0]['digests'][0],
        );
        self::assertSame(
            'developer-portal-v2/keboola.processor-decompress:v4.1.0',
            $outputs[0]->getImages()[1]['id'],
        );
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.processor-decompress@sha256:',
            $outputs[0]->getImages()[1]['digests'][0],
        );
        self::assertSame(
            'developer-portal-v2/keboola.processor-move-files:v2.2.1',
            $outputs[0]->getImages()[2]['id'],
        );
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.processor-move-files@sha256:',
            $outputs[0]->getImages()[2]['digests'][0],
        );
        self::assertSame(
            'developer-portal-v2/keboola.processor-iconv:4.0.0',
            $outputs[0]->getImages()[3]['id'],
        );
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.processor-iconv@sha256:',
            $outputs[0]->getImages()[3]['digests'][0],
        );
        self::assertSame(
            'developer-portal-v2/keboola.r-transformation:1.2.8',
            $outputs[0]->getImages()[4]['id'],
        );
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.r-transformation@sha256:',
            $outputs[0]->getImages()[4]['digests'][0],
        );

        $lines = explode("\n", $outputs[0]->getProcessOutput());
        $lines = array_map(function ($line) {
            return substr($line, 23); // skip the date of event
        }, $lines);
        self::assertEquals([
            0 => ' : Initializing R transformation',
            1 => ' : Running R script',
            2 => ' : R script finished',
        ], $lines);

        $csvData = $this->getClient()->getTableDataPreview('out.c-runner-test.texty');
        $data = Client::parseCsv($csvData);
        self::assertEquals(4, count($data));
        self::assertArrayHasKey('id', $data[0]);
        self::assertArrayHasKey('title', $data[0]);
        self::assertArrayHasKey('text', $data[0]);
        self::assertArrayHasKey('tags', $data[0]);
        self::assertEquals('v123', $outputs[0]->getConfigVersion());
        $this->clearBuckets();
        $this->clearFiles();
    }

    public function testProcessorsImageParameters(): void
    {
        $this->clearBuckets();
        $this->clearFiles();
        $components = [
            [
                'id' => 'keboola.runner-config-test',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.runner-config-test',
                        'tag' => '1.1.0',
                    ],
                    'image_parameters' => ['foo' => 'bar'],
                ],
            ],
        ];
        $clientMock = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->setMethods(['apiGet', 'getServiceUrl'])
            ->getMock();
        $clientMock
            ->method('getServiceUrl')
            ->withConsecutive(['sandboxes'], ['oauth'])
            ->willReturnOnConsecutiveCalls(
                'https://sandboxes.someurl',
                'https://someurl',
            );
        $clientMock
            ->method('apiGet')
            ->willReturnCallback(function ($url, $filename) use ($components) {
                if ($url === 'components/keboola.runner-config-test') {
                    return $components[0];
                } else {
                    return $this->client->apiGet($url, $filename);
                }
            });

        $configurationData = [
            'storage' => [],
            'parameters' => ['operation' => 'dump-config'],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.runner-config-test',
                        ],
                        'parameters' => [
                            'operation' => 'dump-config',
                        ],
                    ],
                ],
            ],
        ];
        $componentData = [
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.runner-config-test',
                    'tag' => '1.1.0',
                ],
                'image_parameters' => ['bar' => 'Kochba'],
            ],
        ];
        $this->setClientMock($clientMock);
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
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
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.runner-config-test:',
            $outputs[0]->getImages()[0]['id'],
        );
        self::assertStringStartsWith(
            'developer-portal-v2/keboola.runner-config-test@sha256:',
            $outputs[0]->getImages()[0]['digests'][0],
        );

        $records = $this->getContainerHandler()->getRecords();
        self::assertStringContainsString('"foo": "bar"', $records[0]['message']);
        self::assertStringContainsString('"bar": "Kochba"', $records[1]['message']);

        $this->clearBuckets();
        $this->clearFiles();
    }

    public function testRunnerProcessorsSyncAction(): void
    {
        $fileTag = 'texty.csv.gz';

        $this->clearBuckets();
        $this->clearFiles();
        $components = [
            [
                'id' => 'keboola.processor-decompress',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress',
                        'tag' => 'v4.1.0',
                    ],
                ],
            ],
        ];
        $clientMock = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->setMethods(['apiGet', 'getServiceUrl'])
            ->getMock();
        $clientMock
            ->method('getServiceUrl')
            ->withConsecutive(['sandboxes'], ['oauth'])
            ->willReturnOnConsecutiveCalls(
                'https://sandboxes.someurl',
                'https://someurl',
            );
        $clientMock
            ->method('apiGet')
            ->willReturnCallback(function ($url, $filename) use ($components) {
                if ($url === 'components/keboola.processor-decompress') {
                    return $components[0];
                } else {
                    return $this->client->apiGet($url, $filename);
                }
            });

        $this->uploadTextTestFile($fileTag);

        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [
                        [
                            'tags' => [$fileTag],
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from os import listdir',
                    'from os.path import isfile, join',
                    'mypath = \'/data/in/files\'',
                    'onlyfiles = [f for f in listdir(mypath)]',
                    'print(onlyfiles)',
                ],
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor-decompress',
                        ],
                    ],
                ],
            ],
        ];
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => '1.1.22',
                ],
            ],
        ];
        $this->setClientMock($clientMock);
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                [],
            ),
            'test-action',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
        self::assertEquals(
            [
                // the processor is not executed
                0 => [
                    'id' => 'developer-portal-v2/keboola.python-transformation:1.1.22',
                    'digests' => [
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'developer-portal-v2/keboola.python-transformation@sha256:34d3a0a9a10cdc9a48b4ab51e057eae85682cf2768d05e9f5344832312ad9f52',
                    ],
                ],
            ],
            $outputs[0]->getImages(),
        );
        $lines = explode("\n", $outputs[0]->getProcessOutput());
        self::assertEquals([
            0 => 'Script file /data/script.py',
            1 => '[]', // there are no files in input directory
            2 => 'Script finished',
        ], $lines);
        self::assertEquals('v123', $outputs[0]->getConfigVersion());
        $this->clearFiles();
    }

    public function testImageParametersDecrypt(): void
    {
        $configurationData = [
           'parameters' => [
               'foo' => 'bar',
               'script' => [
                   'import os',
                   'import sys',
                   'print("KBC_TOKEN: " + (os.environ.get("KBC_TOKEN") or ""))',
                   'print("KBC_CONFIGID: " + (os.environ.get("KBC_CONFIGID") or ""))',
                   'print("KBC_CONFIGVERSION: " + (os.environ.get("KBC_CONFIGVERSION") or ""))',
                   'with open("/data/config.json", "r") as file:',
                   '    print(file.read(), file=sys.stderr)',
               ],
           ],
        ];
        $encrypted = $this->getEncryptor()->encryptForComponent('someString', 'keboola.docker-demo-sync');

        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                ],
                'image_parameters' => [
                    'foo' => 'bar',
                    'baz' => [
                        'lily' => 'pond',
                    ],
                    '#encrypted' => $encrypted,
                ],
            ],
        ];
        $configId = uniqid('test-');
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                $configurationData,
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

        $records = $this->getContainerHandler()->getRecords();
        $contents = '';
        $config = [];
        foreach ($records as $record) {
            if ($record['level'] === Logger::ERROR) {
                $config = json_decode($record['message'], true);
            } else {
                $contents .= $record['message'];
            }
        }

        // verify that the token is not passed by default
        self::assertStringNotContainsString(getenv('STORAGE_API_TOKEN'), $contents);
        self::assertStringContainsString('KBC_CONFIGID: ' . $configId, $contents);
        self::assertStringContainsString('KBC_CONFIGVERSION: v123', $contents);
        unset($config['parameters']['script']);
        self::assertEquals(['foo' => 'bar'], $config['parameters']);
        self::assertEquals(
            ['foo' => 'bar', 'baz' => ['lily' => 'pond'], '#encrypted' => '[hidden]'],
            $config['image_parameters'],
        );
    }

    public function testClearStateWithNamespace(): void
    {
        $this->clearConfigurations();
        $state = [StateFile::NAMESPACE_COMPONENT => ['key' => 'value']];
        $cmp = new Components($this->getClient());
        $cfg = new Configuration();
        $cfg->setComponentId('keboola.docker-demo-sync');
        $cfg->setConfigurationId('runner-configuration');
        $cfg->setConfiguration([]);
        $cfg->setName('Test configuration');
        $cfg->setState($state);
        $cmp->addConfiguration($cfg);

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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                ['parameters' => ['script' => ['import os']]],
                $state,
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
        $cfg = $cmp->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([
            StateFile::NAMESPACE_COMPONENT => [],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [],
                    StateFile::NAMESPACE_FILES => [],
                ],
            ],
        ], $cfg['state']);
        $this->clearConfigurations();
    }

    public function testExecutorDefaultBucketWithDot(): void
    {
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'test', $csv);
        unset($csv);

        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'import json',
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/sliced.csv")',
                    'with open("/data/out/tables/sliced.csv.manifest", "w") as out_file:',
                    '   json.dump({"destination": "sliced"}, out_file)',
                ],
            ],
        ];
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
                'default_bucket_stage' => 'out',
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
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

        self::assertTrue(
            $this->getClient()->tableExists('out.c-keboola-docker-demo-sync-runner-configuration.sliced'),
        );
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Waiting for 1 Storage jobs'));
        $this->clearBuckets();
    }

    public function testExecutorStoreState(): void
    {
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)',
                ],
            ],
        ];
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
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
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([
            StateFile::NAMESPACE_COMPONENT => ['baz' => 'fooBar'],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [],
                    StateFile::NAMESPACE_FILES => [],
                ],
            ],
        ], $configuration['state']);
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateEncryptsValue(): void
    {
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"#encrypted": "secret"}, state_file)',
                ],
            ],
        ];
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
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
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertCount(1, $configuration['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertArrayHasKey('#encrypted', $configuration['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertStringStartsWith(
            'KBC::ProjectSecure::',
            $configuration['state'][StateFile::NAMESPACE_COMPONENT]['#encrypted'],
        );
        self::assertEquals(
            'secret',
            $this->getEncryptor()->decryptForConfiguration(
                $configuration['state'][StateFile::NAMESPACE_COMPONENT]['#encrypted'],
                'keboola.docker-demo-sync',
                $this->getProjectId(),
                'runner-configuration',
            ),
        );
        $this->clearConfigurations();
    }

    public function testExecutorReadNamespacedState(): void
    {
        $state = [StateFile::NAMESPACE_COMPONENT => ['foo' => 'bar']];
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState($state);
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/in/state.json", "r") as state_file_read:',
                    '   data = json.load(state_file_read)',
                    '   assert data["foo"] == "bar"',
                ],
            ],
        ];

        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                $state,
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
        $this->assertNotEmpty($outputs);
    }

    public function testExecutorStoreStateWithProcessor(): void
    {
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)',
                ],
            ],
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-move-files',
                        ],
                        'parameters' => [
                            'direction' => 'tables',
                        ],
                    ],

                ],
            ],
        ];
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
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

        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([
            StateFile::NAMESPACE_COMPONENT => ['baz' => 'fooBar'],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [],
                    StateFile::NAMESPACE_FILES => [],
                ],
            ],
        ], $configuration['state']);
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateRows(): void
    {
        $this->clearBuckets();
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_COMPONENT => ['foo' => 'bar']]);
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $configData1 = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bazRow1": "fooBar1"}, state_file)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-1.csv", "w") as out_table:',
                    '   print("foo,bar\n1,2", file=out_table)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-1.csv.manifest", "w") as out_file:',
                    '   json.dump({"destination": "out.c-runner-test.my-table-1"}, out_file)',
                ],
            ],
        ];
        $configurationRow->setConfiguration($configData1);
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $configData2 = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bazRow2": "fooBar2"}, state_file)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-2.csv", "w") as out_table:',
                    '   print("foo,bar\n1,2", file=out_table)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-2.csv.manifest", "w") as out_file:',
                    '   json.dump({"destination": "out.c-runner-test.my-table-2"}, out_file)',
                ],
            ],
        ];
        $configurationRow->setConfiguration($configData2);
        $component->addConfigurationRow($configurationRow);

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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            [
                new JobDefinition(
                    $configData1,
                    new ComponentSpecification($componentData),
                    'runner-configuration',
                    'v123',
                    [],
                    'row-1',
                ),
                new JobDefinition(
                    $configData2,
                    new ComponentSpecification($componentData),
                    'runner-configuration',
                    'v123',
                    [],
                    'row-2',
                ),
            ],
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );

        $component = new Components($this->getClient());
        $listOptions = new ListConfigurationRowsOptions();
        $listOptions->setComponentId('keboola.docker-demo-sync')->setConfigurationId('runner-configuration');
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        // configuration state should be unchanged
        self::assertArrayHasKey('foo', $configuration['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertEquals('bar', $configuration['state'][StateFile::NAMESPACE_COMPONENT]['foo']);
        $rows = $component->listConfigurationRows($listOptions);
        uasort(
            $rows,
            function ($a, $b) {
                return strcasecmp($a['id'], $b['id']);
            },
        );
        $row1 = $rows[0];
        $row2 = $rows[1];
        self::assertArrayHasKey('bazRow1', $row1['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertEquals('fooBar1', $row1['state'][StateFile::NAMESPACE_COMPONENT]['bazRow1']);
        self::assertArrayHasKey('bazRow2', $row2['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertEquals('fooBar2', $row2['state'][StateFile::NAMESPACE_COMPONENT]['bazRow2']);
        self::assertTrue(
            $this->getRunnerHandler()->hasInfoThatContains('Running component keboola.docker-demo-sync (row 1 of 2)'),
        );
        self::assertTrue(
            $this->getRunnerHandler()->hasInfoThatContains('Running component keboola.docker-demo-sync (row 2 of 2)'),
        );
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Waiting for 2 Storage jobs'));
        self::assertTrue($this->client->tableExists('out.c-runner-test.my-table-1'));
        self::assertTrue($this->client->tableExists('out.c-runner-test.my-table-2'));
        $this->clearConfigurations();
    }

    public function testSynchronousOutputMappingErrorsAreReported(): void
    {
        $this->clearBuckets();
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_COMPONENT => ['foo' => 'bar']]);
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $configData1 = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bazRow1": "fooBar1"}, state_file)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-1.csv", "w") as out_table:',
                    '   print("foo,foo\n1,2", file=out_table)',
                ],
            ],
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'out.c-runner-test.my-table-1.csv',
                            'destination' => 'out.c-runner-test.my-table-1',
                        ],
                    ],
                ],
            ],
        ];
        $configurationRow->setConfiguration($configData1);
        $component->addConfigurationRow($configurationRow);

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

        $runner = $this->getRunner();
        try {
            $outputs = [];
            $runner->run(
                [
                    new JobDefinition(
                        $configData1,
                        new ComponentSpecification($componentData),
                        'runner-configuration',
                        'v123',
                        [],
                        'row-1',
                    ),
                ],
                'run',
                'run',
                '1234567',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                'Failed to process output mapping: Failed to load table "out.c-runner-test.my-table-1": There are duplicate columns in CSV file: "foo"',
                $e->getMessage(),
            );
        }

        $component = new Components($this->getClient());
        $listOptions = new ListConfigurationRowsOptions();
        $listOptions->setComponentId('keboola.docker-demo-sync')->setConfigurationId('runner-configuration');
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        // configuration state should be unchanged
        self::assertArrayHasKey('foo', $configuration['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertEquals('bar', $configuration['state'][StateFile::NAMESPACE_COMPONENT]['foo']);
        $row = $component->listConfigurationRows($listOptions)[0];

        self::assertArrayNotHasKey('component', $row['state']);
        self::assertFalse($this->client->tableExists('out.c-runner-test.my-table-1'));
        self::assertFalse($this->getRunnerHandler()->hasInfoThatContains('Waiting for 1 storage jobs'));
        $this->clearConfigurations();
    }

    public function testAsynchronousOutputMappingErrorsAreReported(): void
    {
        // https://keboola.slack.com/archives/CFVRE56UA/p1658815231303589
        $this->markTestSkipped('Skipped due to connection bug');
        $this->clearBuckets();
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_COMPONENT => ['foo' => 'bar']]);
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $configurationRow->setState([StateFile::NAMESPACE_COMPONENT => ['fooRow1' => 'barRow1']]);
        $configData1 = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bazRow1": "fooBar1"}, state_file)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-1.csv", "w") as out_table:',
                    '   print("foo,bar\n1,2,3", file=out_table)',
                ],
            ],
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'out.c-runner-test.my-table-1.csv',
                            'destination' => 'out.c-runner-test.my-table-1',
                        ],
                    ],
                ],
            ],
        ];
        $configurationRow->setConfiguration($configData1);
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $configurationRow->setState([StateFile::NAMESPACE_COMPONENT => ['fooRow2' => 'barRow2']]);
        $configData2 = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bazRow2": "fooBar2"}, state_file)',
                    'with open("/data/out/tables/out.c-runner-test.my-table-2.csv", "w") as out_table:',
                    '   print("foo,bar\n1,2", file=out_table)',
                ],
            ],
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'out.c-runner-test.my-table-2.csv',
                            'destination' => 'out.c-runner-test.my-table-2',
                        ],
                    ],
                ],
            ],
        ];
        $configurationRow->setConfiguration($configData2);
        $component->addConfigurationRow($configurationRow);

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

        $runner = $this->getRunner();
        try {
            $outputs = [];
            $runner->run(
                [
                    new JobDefinition(
                        $configData1,
                        new ComponentSpecification($componentData),
                        'runner-configuration',
                        'v123',
                        [],
                        'row-1',
                    ),
                    new JobDefinition(
                        $configData2,
                        new ComponentSpecification($componentData),
                        'runner-configuration',
                        'v123',
                        [],
                        'row-2',
                    ),
                ],
                'run',
                'run',
                '1234567',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Failed to process output mapping: Failed to load table "out.c-runner-test.my-table-1": Load error',
                $e->getMessage(),
            );
        }

        $component = new Components($this->getClient());
        $listOptions = new ListConfigurationRowsOptions();
        $listOptions->setComponentId('keboola.docker-demo-sync')->setConfigurationId('runner-configuration');
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        // configuration state should be unchanged
        self::assertArrayHasKey('foo', $configuration['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertEquals('bar', $configuration['state'][StateFile::NAMESPACE_COMPONENT]['foo']);
        $rows = $component->listConfigurationRows($listOptions);
        uasort(
            $rows,
            function ($a, $b) {
                return strcasecmp($a['id'], $b['id']);
            },
        );
        $row1 = $rows[0];
        $row2 = $rows[1];
        self::assertArrayNotHasKey('bazRow1', $row1['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertArrayNotHasKey('bazRow2', $row2['state'][StateFile::NAMESPACE_COMPONENT]);
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Waiting for 2 Storage jobs'));
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateWithProcessorError(): void
    {
        $this->clearConfigurations();
        $runner = $this->getRunner();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_COMPONENT => ['foo' => 'bar']]);
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)',
                ],
            ],
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-move-files',
                        ],
                        // required parameter direction is missing
                    ],

                ],
            ],
        ];
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
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

        try {
            $outputs = [];
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'runner-configuration',
                    $configData,
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
            self::fail('Must fail with user error');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'child node "direction" at path "parameters" must be configured.',
                $e->getMessage(),
            );
        }

        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals(
            [StateFile::NAMESPACE_COMPONENT => ['foo' => 'bar']],
            $configuration['state'],
            'State must not be changed',
        );
        $this->clearConfigurations();
    }

    public function testExecutorAfterProcessorNoState(): void
    {
        $this->clearConfigurations();
        $components = [
            [
                'id' => 'keboola.docker-demo-sync',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    ],
                ],
            ],
            [
                'id' => 'keboola.processor-dumpy',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                        'tag' => 'latest',
                    ],
                ],
            ],
        ];
        $clientMock = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->setMethods(['getServiceUrl', 'apiGet'])
            ->getMock();
        $clientMock
            ->method('apiGet')
            ->willReturnCallback(function ($url, $filename) use ($components) {
                if ($url === 'components/keboola.docker-demo-sync') {
                    return $components[0];
                } elseif ($url === 'components/keboola.processor-dumpy') {
                    return $components[1];
                } else {
                    return $this->client->apiGet($url, $filename);
                }
            });
        $clientMock
            ->method('getServiceUrl')
            ->withConsecutive(['sandboxes'], ['oauth'])
            ->willReturnOnConsecutiveCalls(
                'https://sandboxes.someurl',
                'https://someurl',
            );
        $this->setClientMock($clientMock);

        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bar": "Kochba"}, state_file)',
                ],
            ],
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-dumpy',
                        ],
                        'parameters' => [
                            'script' => [
                                'from os import listdir',
                                'print([f for f in listdir("/data/in/")])',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $components[0],
                'runner-configuration',
                $configData,
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

        $records = $this->getContainerHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $output = '';
        foreach ($records as $record) {
            $output .= $record['message'];
        }
        self::assertStringContainsString('files', $output);
        self::assertStringContainsString('tables', $output);
        self::assertStringNotContainsString('state', $output, "No state must've been passed to the processor");
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals(
            ['bar' => 'Kochba'],
            $configuration['state'][StateFile::NAMESPACE_COMPONENT],
            'State must be changed',
        );
    }

    public function testExecutorBeforeProcessorNoState(): void
    {
        $this->clearConfigurations();
        $components = [
            [
                'id' => 'keboola.docker-demo-sync',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    ],
                ],
            ],
            [
                'id' => 'keboola.processor-dumpy',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                        'tag' => 'latest',
                    ],
                ],
            ],
        ];
        $clientMock = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => getenv('STORAGE_API_URL'),
                    'token' => getenv('STORAGE_API_TOKEN'),
                ],
            ])
            ->setMethods(['apiGet', 'getServiceUrl'])
            ->getMock();
        $clientMock
            ->method('getServiceUrl')
            ->withConsecutive(['sandboxes'], ['oauth'])
            ->willReturnOnConsecutiveCalls(
                'https://sandboxes.someurl',
                'https://someurl',
            );
        $clientMock
            ->method('apiGet')
            ->willReturnCallback(function ($url, $filename) use ($components) {
                if ($url === 'components/keboola.docker-demo-sync') {
                    return $components[0];
                } elseif ($url === 'components/keboola.processor-dumpy') {
                    return $components[1];
                } else {
                    return $this->client->apiGet($url, $filename);
                }
            })
        ;

        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"bar": "Kochba"}, state_file)',
                ],
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-dumpy',
                        ],
                        'parameters' => [
                            'script' => [
                                'from os import listdir',
                                'print([f for f in listdir("/data/in/")])',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->setClientMock($clientMock);
        $component = new Components($clientMock);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $components[0],
                'runner-configuration',
                $configData,
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

        $records = $this->getContainerHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $output = '';
        foreach ($records as $record) {
            $output .= $record['message'];
        }
        self::assertStringContainsString('files', $output);
        self::assertStringContainsString('tables', $output);
        self::assertStringNotContainsString('state', $output, "No state must've been passed to the processor");
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals(
            ['bar' => 'Kochba'],
            $configuration['state'][StateFile::NAMESPACE_COMPONENT],
            'State must be changed',
        );
    }

    public function testExecutorNoStoreState(): void
    {
        $this->clearConfigurations();
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)',
                ],
            ],
        ];
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
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
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

        $component = new Components($this->getClient());
        $this->expectException(ClientException::class);
        self:$this->expectExceptionMessage('Configuration "runner-configuration" not found');
        $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
    }

    public function testExecutorStateNoConfigId(): void
    {
        $this->clearConfigurations();
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
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                null,
                $configData,
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

        $component = new Components($this->getClient());
        $this->expectException(ClientException::class);
        self:$this->expectExceptionMessage('Configuration "runner-configuration" not found');
        $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
    }

    public function testExecutorNoConfigIdNoMetadata(): void
    {
        $this->clearConfigurations();
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
        $configData = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'data.csv',
                            'destination' => 'in.c-keboola-docker-demo-sync.some-table',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'with open("/data/out/tables/data.csv", "w") as file:',
                    '   file.write("id,name\n1,test")',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                null,
                $configData,
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
        $metadataApi = new Metadata($this->getClient());
        $bucketMetadata = $metadataApi->listBucketMetadata('in.c-keboola-docker-demo-sync');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'keboola.docker-demo-sync',
            ],
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));
    }

    public function testExecutorInvalidConfiguration(): void
    {
        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [
                        [
                            'tags' => ['tde'],
                            /* unrecognized option -> */
                            'filterByRunId' => true,
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'mode' => true,
                'credentials' => 'tde-exporter-tde-bug-32',
            ],
        ];
        $runner = $this->getRunner();
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

        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage('Unrecognized option "filterByRunId');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
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

    public function testExecutorDefaultBucketNoStage(): void
    {
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'test', $csv);
        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import json',
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/sliced")',
                    'with open("/data/out/tables/sliced.manifest", "w") as out_file:',
                    '   json.dump({"destination": "sliced"}, out_file)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
            ],
        ];

        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
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

        self::assertTrue($this->getClient()->tableExists('in.c-keboola-docker-demo-sync-runner-configuration.sliced'));
        $this->clearBuckets();
    }

    public function testExecutorSyncActionNoStorage(): void
    {
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'test', $csv);
        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/sliced")',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
                'synchronous_actions' => [],
            ],
        ];

        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage("No such file or directory: '/data/in/tables/source.csv'");
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
                [],
            ),
            'some-sync-action',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testExecutorNoStorage(): void
    {
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'test', $csv);

        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/sliced")',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'staging_storage' => [
                    'input' => 'none',
                ],
                'default_bucket' => true,
            ],
        ];

        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage("No such file or directory: '/data/in/tables/source.csv'");
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
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

    public function testExecutorApplicationError(): void
    {
        $fileTag = 'texty.csv.gz';

        $this->clearFiles();

        $this->uploadTextTestFile($fileTag);

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
        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                        ],
                    ],
                    'files' => [
                        [
                            'tags' => [$fileTag],
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'in.c-runner-test.out',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import sys',
                    'print("Class 2 error", file=sys.stderr)',
                    'sys.exit(2)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
                'run',
                'run',
                '1234567',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
        } catch (ApplicationExceptionInterface $e) {
            self::assertStringContainsString(
                'developer-portal-v2/' .
                'keboola.python-transformation:latest container \'1234567-norunid--0-keboola-docker-demo-sync\'' .
                ' failed: (2) Class 2 error',
                $e->getMessage(),
            );
            self::assertOutputsContainInputTableResult($outputs);
            self::assertOutputsContainInputFileStateList($outputs, $fileTag);

            // @phpstan-ignore-next-line
            self::assertCount(1, $outputs);
            /** @var Output $output */
            $output = array_shift($outputs);
            self::assertEquals('v123', $output->getConfigVersion());
            self::assertCount(1, $output->getImages());
            self::assertArrayHasKey('id', $output->getImages()[0]);
            self::assertArrayHasKey('digests', $output->getImages()[0]);
            self::assertEquals(
                'developer-portal-v2/keboola.python-transformation:latest',
                $output->getImages()[0]['id'],
            );
        }
    }

    public function testExecutorUserError(): void
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
        $configurationData = [
            'parameters' => [
                'script' => [
                    'import sys',
                    'print("Class 1 error", file=sys.stderr)',
                    'sys.exit(1)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage('Class 1 error');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testExecutorApplicationErrorDisabled(): void
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'logging' => [
                    'no_application_errors' => true,
                ],
            ],
        ];
        $configurationData = [
            'parameters' => [
                'script' => [
                    'import sys',
                    'print("Class 2 error", file=sys.stderr)',
                    'sys.exit(2)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage('Class 2 error');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testExecutorApplicationErrorDisabledButStillError(): void
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '',
                ],
                'logging' => [
                    'no_application_errors' => true,
                ],
            ],
        ];
        $configurationData = [
            'parameters' => [
                'script' => [
                    'import sys',
                    'print("Class 2 error", file=sys.stderr)',
                    'sys.exit(2)',
                ],
            ],
        ];
        $runner = $this->getRunner();

        $this->expectException(ApplicationExceptionInterface::class);
        $this->expectExceptionMessage('Component definition is invalid');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testExecutorInvalidInputMapping(): void
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
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                            // erroneous lines
                            'foo' => 'bar',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'in.c-runner-test.out',
                        ],
                    ],
                ],
            ],
        ];
        $runner = $this->getRunner();
        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage('Unrecognized option "foo" under "container.storage.input.tables.0"');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $config, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testExecutorFillsInputFilesStateAndInputTableResultOnUserError(): void
    {
        $fileTag = 'texty.csv.gz';

        $this->clearFiles();

        $this->uploadTextTestFile($fileTag);

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
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                        ],
                    ],
                    'files' => [
                        [
                            'tags' => [$fileTag],
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'in.c-runner-test.out',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import os',
                ],
            ],
        ];
        $runner = $this->getRunner();

        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions($componentData, 'runner-configuration', $config, []),
                'run',
                'run',
                '1234567',
                new NullUsageFile(),
                [],
                $outputs,
                null,
            );
            $this->fail('Run action should fail with UserException');
        } catch (UserExceptionInterface $e) {
            self::assertSame('Table sources not found: "sliced.csv"', $e->getMessage());
        }

        self::assertOutputsContainInputTableResult($outputs);
        self::assertOutputsContainInputFileStateList($outputs, $fileTag);
    }

    public function testExecutorInvalidInputMapping2(): void
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
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-runner-test.test',
                            // erroneous lines
                            'columns' => [
                                [
                                    'value' => 'id',
                                    'label' => 'id',
                                ],
                                [
                                    'value' => 'col1',
                                    'label' => 'col1',
                                ],
                            ],
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'in.c-runner-test.out',
                        ],
                    ],
                ],
            ],
        ];
        $runner = $this->getRunner();
        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage('Invalid type for path "container.storage.input.tables.0.columns.0".');
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $config, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );
    }

    public function testExecutorSlicedFilesWithComponentRootUserFeature(): void
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
            'features' => [
                'container-root-user',
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
                    'call(["chmod", "000", "/data/out/tables/mytable.csv.gz/part1"])',
                    'with open("/data/out/tables/mytable.csv.gz/part2", "w") as file:',
                    '   file.write("value2")',
                    'call(["chmod", "000", "/data/out/tables/mytable.csv.gz/part2"])',
                ],
            ],
        ];
        $runner = $this->getRunner();
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

        self::assertTrue($this->getClient()->tableExists('in.c-runner-test.mytable'));
        $lines = explode("\n", $this->getClient()->getTableDataPreview('in.c-runner-test.mytable'));
        sort($lines);
        self::assertEquals(
            [
                '',
                '"col1"',
                '"value1"',
                '"value2"',
            ],
            $lines,
        );
    }

    public function testExecutorSlicedFilesWithoutComponentRootUserFeature(): void
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
                    'with open("/data/out/tables/mytable.csv.gz/part1", "w") as file:',
                    '   file.write("value1")',
                    'with open("/data/out/tables/mytable.csv.gz/part2", "w") as file:',
                    '   file.write("value2")',
                ],
            ],
        ];

        $runner = $this->getRunner();
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
        self::assertTrue($this->getClient()->tableExists('in.c-runner-test.mytable'));
    }

    public function testAuthorizationDecrypt(): void
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
        $configurationData = [
            'parameters' => [
                '#one' => 'bar',
                'two' => 'anotherBar',
                'script' => [
                    'import sys',
                    'import os',
                    'with open("/data/config.json", "r") as file:',
                    '    print(file.read(), file=sys.stderr)',
                ],
            ],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#three' => 'foo',
                        'four' => 'anotherFoo',
                    ],
                    'version' => 3,
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
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

        $records = $this->getContainerHandler()->getRecords();
        $error = '';
        foreach ($records as $record) {
            if ($record['level'] === Logger::ERROR) {
                $error .= $record['message'];
            }
        }
        $config = json_decode($error, true);
        self::assertEquals('[hidden]', $config['parameters']['#one']);
        self::assertEquals('anotherBar', $config['parameters']['two']);
        self::assertEquals('[hidden]', $config['authorization']['oauth_api']['credentials']['#three']);
        self::assertEquals('anotherFoo', $config['authorization']['oauth_api']['credentials']['four']);
    }

    public function testTokenObfuscate(): void
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'forward_token' => true,
            ],
        ];
        $configurationData = [
            'parameters' => [
                'script' => [
                    'import os',
                    'print(os.environ["KBC_TOKEN"])',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
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

        $records = $this->getContainerHandler()->getRecords();
        $output = '';
        foreach ($records as $record) {
            $output .= $record['message'];
        }
        self::assertStringNotContainsString(getenv('STORAGE_API_TOKEN'), $output);
        self::assertStringContainsString('[hidden]', $output);
    }

    public function testExecutorStoreUsage(): void
    {
        $this->clearConfigurations();
        $usageFile = new TestUsageFile();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $component->addConfiguration($configuration);
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
        $configData = [
            'parameters' => [
                'script' => [
                    'with open("/data/out/usage.json", "w") as file:',
                    '   file.write(\'[{"metric": "kB", "value": 150}]\')',
                ],
            ],
        ];
        $jobDefinition = new JobDefinition(
            $configData,
            new ComponentSpecification($componentData),
            'runner-configuration',
        );
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run([$jobDefinition], 'run', 'run', '987654', $usageFile, [], $outputs, null);
        self::assertEquals(
            [[[
                'metric' => 'kB',
                'value' => 150,
            ]]],
            $usageFile->getUsageData(),
        );
    }

    public function testExecutorStoreVariables(): void
    {
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $component->addConfiguration($configuration);
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
        $configData = [
            'parameters' => [
                'script' => [
                    'print("Hello world.")',
                ],
            ],
        ];
        $variableValues = [
            'foo' => 'bar',
        ];
        $jobDefinition = new JobDefinition(
            configuration: $configData,
            component: new ComponentSpecification($componentData),
            configId: 'runner-configuration',
            inputVariableValues: $variableValues,
        );
        $runner = $this->getRunner();

        /** @var Output[] $outputs */
        $outputs = [];
        $runner->run(
            [$jobDefinition],
            'run',
            'run',
            '987654',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );

        self::assertCount(1, $outputs);
        $output = reset($outputs);
        self::assertSame($variableValues, $output->getInputVariableValues());
    }

    public function testExecutorStoreRowsUsage(): void
    {
        $this->clearConfigurations();
        $usageFile = new TestUsageFile();

        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $component->addConfigurationRow($configurationRow);

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
        $configData = [
            'parameters' => [
                'script' => [
                    'with open("/data/out/usage.json", "w") as file:',
                    '   file.write(\'[{"metric": "kB", "value": 150}]\')',
                ],
            ],
        ];

        $jobDefinition1 = new JobDefinition(
            $configData,
            new ComponentSpecification($componentData),
            'runner-configuration',
            null,
            [],
            'row-1',
        );
        $jobDefinition2 = new JobDefinition(
            $configData,
            new ComponentSpecification($componentData),
            'runner-configuration',
            null,
            [],
            'row-2',
        );
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            [$jobDefinition1, $jobDefinition2],
            'run',
            'run',
            '987654',
            $usageFile,
            [],
            $outputs,
            null,
        );
        self::assertEquals(
            [
                [[
                    'metric' => 'kB',
                    'value' => 150,
                ]],
                [[
                    'metric' => 'kB',
                    'value' => 150,
                ]],
            ],
            $usageFile->getUsageData(),
        );
    }

    /**
     * @dataProvider swapFeatureProvider
     */
    public function testExecutorSwap($features): void
    {
        $this->clearConfigurations();
        $usageFile = new NullUsageFile();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $component->addConfiguration($configuration);
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
            'features' => $features,
        ];
        $configData = [
            'parameters' => [
                'script' => [],
            ],
        ];
        $jobDefinition = new JobDefinition(
            $configData,
            new ComponentSpecification($componentData),
            'runner-configuration',
        );
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run([$jobDefinition], 'run', 'run', '987654', $usageFile, [], $outputs, null);
        self::assertCount(1, $outputs);
        self::assertEquals("Script file /data/script.py\nScript finished", $outputs[0]->getProcessOutput());
    }

    public function testRunAdaptiveInputMapping(): void
    {
        $this->createBuckets();
        $this->clearConfigurations();

        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
        unset($csv);

        $tableInfo = $this->getClient()->getTable('in.c-runner-test.mytable');

        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test2', 'test2']);
        $this->getClient()->writeTableAsync('in.c-runner-test.mytable', $csv, ['incremental' => true]);
        unset($csv);

        $updatedTableInfo = $this->getClient()->getTable('in.c-runner-test.mytable');

        self::assertNotEquals($tableInfo['lastImportDate'], $updatedTableInfo['lastImportDate']);

        file_put_contents($temp->getTmpFolder() . '/upload', 'test');
        $fileId1 = $this->getClient()->uploadFile(
            $temp->getTmpFolder() . '/upload',
            (new FileUploadOptions())->setTags([self::RUNNER_TEST_FILES_TAG, 'file1']),
        );
        $fileId2 = $this->getClient()->uploadFile(
            $temp->getTmpFolder() . '/upload',
            (new FileUploadOptions())->setTags([self::RUNNER_TEST_FILES_TAG, 'file2']),
        );
        sleep(2);

        $componentDefinition = new ComponentSpecification([
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
            'features' => [
                'container-root-user',
            ],
        ]);

        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId($componentDefinition->getId());
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $component->addConfiguration($configuration);

        $jobDefinition1 = new JobDefinition(
            [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-runner-test.mytable',
                                'destination' => 'mytable',
                                'changed_since' => InputTableOptions::ADAPTIVE_INPUT_MAPPING_VALUE,
                            ],
                        ],
                        'files' => [
                            [
                                'tags' => [self::RUNNER_TEST_FILES_TAG],
                                'changed_since' => InputTableOptions::ADAPTIVE_INPUT_MAPPING_VALUE,
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'mytable',
                                'destination' => 'in.c-runner-test.mytable-2',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'script' => [
                        'import glob, os',
                        'from shutil import copyfile',
                        'copyfile("/data/in/tables/mytable", "/data/out/tables/mytable")',
                        'for f in glob.glob("/data/in/files/*"):',
                        '    print(f)',
                    ],
                ],
            ],
            $componentDefinition,
            'runner-configuration',
            null,
            [
                StateFile::NAMESPACE_STORAGE => [
                    StateFile::NAMESPACE_INPUT => [
                        StateFile::NAMESPACE_TABLES => [
                            [
                                'source' => 'in.c-runner-test.mytable',
                                'lastImportDate' => $tableInfo['lastImportDate'],
                            ],
                        ],
                        StateFile::NAMESPACE_FILES => [
                            [
                                'tags' => [
                                    [
                                        'name' => self::RUNNER_TEST_FILES_TAG,
                                    ],
                                ],
                                'lastImportId' => $fileId1,
                            ],
                        ],
                    ],
                ],
            ],
        );

        $jobDefinitions = [$jobDefinition1];
        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567',
            new NullUsageFile(),
            [],
            $outputs,
            null,
        );

        // the script logs all the input files, so fileId2 should be there, but not fileId1
        $this->assertTrue($this->getContainerHandler()->hasInfoThatContains('/data/in/files/' . $fileId2 . '_upload'));
        $this->assertFalse($this->getContainerHandler()->hasInfoThatContains('/data/in/files/' . $fileId1 . '_upload'));

        self::assertTrue($this->getClient()->tableExists('in.c-runner-test.mytable-2'));
        $outputTableInfo = $this->getClient()->getTable('in.c-runner-test.mytable-2');
        self::assertEquals(1, $outputTableInfo['rowsCount']);

        $configuration = $component->getConfiguration($componentDefinition->getId(), 'runner-configuration');
        self::assertEquals(
            ['source' => 'in.c-runner-test.mytable', 'lastImportDate' => $updatedTableInfo['lastImportDate']],
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            $configuration['state'][StateFile::NAMESPACE_STORAGE][StateFile::NAMESPACE_INPUT][StateFile::NAMESPACE_TABLES][0],
        );
        // confirm that the file state is correct
        self::assertEquals(
            ['tags' => [['name' => 'docker-runner-test']], 'lastImportId' => $fileId2],
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            $configuration['state'][StateFile::NAMESPACE_STORAGE][StateFile::NAMESPACE_INPUT][StateFile::NAMESPACE_FILES][0],
        );
    }

    public function swapFeatureProvider(): array
    {
        return [
            [['no-swap']],
            [[]],
        ];
    }

    public function testWorkspaceMapping(): void
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-workspace-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        self::assertFalse($this->client->tableExists('out.c-runner-test.new-table'));
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-runner-test.mytable',
                                    'destination' => 'local-table',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'local-table',
                                    'destination' => 'out.c-runner-test.new-table',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'copy',
                    ],
                ],
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

        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-test');
        $options->setConfigurationId($configId);
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
        self::assertTrue($this->client->tableExists('out.c-runner-test.new-table'));
        $components->deleteConfiguration('keboola.runner-workspace-test', $configId);
    }

    public function testWorkspaceMappingCleanupComponentError(): void
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-workspace-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        try {
            $outputs = [];
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    $configId,
                    [
                        'storage' => [
                            'input' => [
                                'tables' => [
                                    [
                                        'source' => 'in.c-runner-test.mytable',
                                        'destination' => 'local-table',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'operation' => 'copy',
                        ],
                    ],
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString('One input and output mapping is required.', $e->getMessage());
            $options = new ListConfigurationWorkspacesOptions();
            $options->setComponentId('keboola.runner-workspace-test');
            $options->setConfigurationId($configId);
            self::assertCount(0, $components->listConfigurationWorkspaces($options));
            self::assertFalse($this->client->tableExists('out.c-runner-test.new-table'));
            $components->deleteConfiguration('keboola.runner-workspace-test', $configId);
        }
    }

    public function testWorkspaceMappingCleanupMappingError(): void
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-workspace-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-workspace-test',
                    'tag' => 'latest',
                ],
                'staging_storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        try {
            $outputs = [];
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    $configId,
                    [
                        'storage' => [
                            'input' => [
                                'tables' => [
                                    [
                                        'source' => 'in.c-runner-test.mytable',
                                        'destination' => 'local-table',
                                    ],
                                ],
                            ],
                            'output' => [
                                'tables' => [
                                    [
                                        'source' => 'local-table-new-table',
                                        'destination' => 'in.c-runner-test.new-table',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'operation' => 'copy-snowflake-error',
                        ],
                    ],
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Some columns are missing in the csv file. Missing columns: invalid_column',
                $e->getMessage(),
            );
            $options = new ListConfigurationWorkspacesOptions();
            $options->setComponentId('keboola.runner-workspace-test');
            $options->setConfigurationId($configId);
            self::assertCount(0, $components->listConfigurationWorkspaces($options));
            self::assertFalse($this->client->tableExists('out.c-runner-test.new-table'));
            $components->deleteConfiguration('keboola.runner-workspace-test', $configId);
        }
    }

    public function testS3StagingMapping(): void
    {
        $this->clearBuckets();
        $this->createBuckets();
        $temp = new Temp();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test1', 'test1']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'mytable', $csv);
        unset($csv);

        $componentData = [
            'id' => 'keboola.runner-staging-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                    'tag' => '0.0.3',
                ],
                'staging_storage' => [
                    'input' => 's3',
                    'output' => 'local',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-staging-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        self::assertFalse($this->client->tableExists('out.c-runner-test.new-table'));
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-runner-test.mytable',
                                    'destination' => 'local-table',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'content',
                        'filename' => 'local-table.manifest',
                    ],
                ],
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

        $records = $this->getContainerHandler()->getRecords();
        self::assertCount(1, $records);
        $message = $records[0]['message'];
        $manifestData = json_decode($message, true);
        self::assertEquals('in.c-runner-test.mytable', $manifestData['id']);
        self::assertEquals('mytable', $manifestData['name']);
        self::assertArrayHasKey('last_change_date', $manifestData);
        self::assertArrayHasKey('s3', $manifestData);
        self::assertArrayHasKey('region', $manifestData['s3']);
        self::assertArrayHasKey('bucket', $manifestData['s3']);
        self::assertArrayHasKey('key', $manifestData['s3']);
        self::assertArrayHasKey('access_key_id', $manifestData['s3']['credentials']);
        $components->deleteConfiguration('keboola.runner-staging-test', $configId);
    }

    public function testStorageFilesOutputProcessed(): void
    {
        $fileTag = 'texty.csv.gz';

        $this->clearFiles();
        $this->uploadTextTestFile($fileTag);

        $componentData = [
            'id' => 'keboola.runner-staging-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                    'tag' => '0.1.0',
                ],
                'staging_storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-staging-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'input' => [
                            'files' => [
                                [
                                    'tags' => [$fileTag],
                                    'processed_tags' => ['processed'],
                                ],
                            ],
                        ],
                        'output' => [
                            'files' => [
                                [
                                    'source' => 'my-file.dat',
                                    'tags' => [self::RUNNER_TEST_FILES_TAG],
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-output-file-local',
                        'filename' => 'my-file.dat',
                    ],
                ],
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
        // wait for the file to show up in the listing
        sleep(2);
        $fileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:"docker-runner-test"' .
            ' AND tags:"componentId: keboola.runner-staging-test" AND tags:' .
            sprintf('"configurationId: %s"', $configId),
        ));
        self::assertCount(1, $fileList);
        self::assertEquals('my_file.dat', $fileList[0]['name']);

        // check that the input file is now tagged as processed
        $inputFileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:"docker-runner-test" AND tags:"processed"',
        ));
        self::assertCount(1, $inputFileList);
    }

    public function testOutputTablesAsFiles(): void
    {
        $this->clearFiles();
        $this->clearBuckets();
        $componentData = [
            'id' => 'keboola.runner-staging-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                    'tag' => '0.1.0',
                ],
                'staging_storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
            'features' => ['allow-use-file-storage-only'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-staging-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'output' => [
                            'files' => [],
                            'tables' => [],
                            'table_files' => [
                                'tags' => ['foo', self::RUNNER_TEST_FILES_TAG],
                                'is_permanent' => false,
                            ],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-output-table-local',
                        'filename' => 'my-table.csv',
                        'includeManifest' => true,
                    ],
                    'runtime' => [
                        'use_file_storage_only' => true,
                    ],
                ],
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
        // wait for the file to show up in the listing
        sleep(2);

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.test-table'));

        // but the file should exist
        $fileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:"componentId: keboola.runner-staging-test" AND tags:' .
            sprintf('"configurationId: %s"', $configId),
        ));
        self::assertCount(1, $fileList);
        self::assertEquals('my_table.csv', $fileList[0]['name']);
        self::assertTrue(in_array('foo', $fileList[0]['tags']));
        self::assertTrue(in_array(self::RUNNER_TEST_FILES_TAG, $fileList[0]['tags']));
        self::assertNotNull($fileList[0]['maxAgeDays']);
    }

    public function testOutputTablesAsFilesWithMissingTableFiles(): void
    {
        $this->clearFiles();
        $this->clearBuckets();
        $componentData = [
            'id' => 'keboola.runner-staging-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                    'tag' => '0.1.0',
                ],
                'staging_storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
            'features' => ['allow-use-file-storage-only'],
        ];

        $configId = uniqid('runner-test-');
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-staging-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId($configId);
        $components->addConfiguration($configuration);
        $runner = $this->getRunner();

        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                [
                    'storage' => [
                        'output' => [
                            'files' => [],
                            'tables' => [],
                        ],
                    ],
                    'parameters' => [
                        'operation' => 'create-output-table-local',
                        'filename' => 'my-table.csv',
                        'includeManifest' => false,
                    ],
                    'runtime' => [
                        'use_file_storage_only' => true,
                    ],
                ],
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

        // wait for the file to show up in the listing
        sleep(2);

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.test-table'));

        // but the file should exist
        $fileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:"componentId: keboola.runner-staging-test" AND tags:' .
            sprintf('"configurationId: %s"', $configId),
        ));
        self::assertCount(1, $fileList);
        self::assertEquals('my_table.csv', $fileList[0]['name']);
    }

    public function testOutputTablesOnJobFailureManifest(): void
    {
        $this->clearFiles();
        $this->clearBuckets();

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
        $configurationData = [
            'storage' => [
                'output' => [
                    'files' => [],
                    'tables' => [],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import csv',
                    'import sys',
                    'import json',
                    'with open("out/tables/write-always.csv", mode="wt", encoding="utf-8") as out_file:',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    '    writer = csv.DictWriter(out_file, fieldnames=["col1", "col2"], lineterminator="\n", delimiter=",", quotechar=\'"\')',
                    '    writer.writeheader()',
                    '    writer.writerow({\'col1\': \'hello\', \'col2\': \'world\'})',
                    'manifest = {"destination": "out.c-runner-test.write-always", "write_always": True}',
                    'out_file = open("out/tables/write-always.csv.manifest", "w")',
                    'json.dump(manifest, out_file, indent=2)',
                    'out_file.close()',
                    'print("Class 1 error", file=sys.stderr)',
                    'sys.exit(1)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'runner-configuration',
                    $configurationData,
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Class 1 error',
                $e->getMessage(),
            );
        }

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.write-on-success'));

        // but the write-always table should exist
        self::assertTrue($this->client->tableExists('out.c-runner-test.write-always'));
    }

    public function testOutputTablesOnJobFailureWorkspace(): void
    {
        $this->clearFiles();
        $this->clearBuckets();

        $componentData = [
            'id' => 'keboola.snowflake-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.snowflake-transformation',
                    'tag' => '0.7.0',
                ],
                'staging_storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ];
        $configurationData = [
            'storage' => [
                'output' => [
                    'files' => [],
                    'tables' => [
                        [
                            'source' => 'write-on-success',
                            'destination' => 'out.c-runner-test.write-on-success',
                        ],
                        [
                            'source' => 'write-always',
                            'destination' => 'out.c-runner-test.write-always',
                            'write_always' => true,
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'blocks' => [
                    [
                        'name' => 'Block 1',
                        'codes' => [
                            [
                                'name' => 'Main',
                                'script' => [
                                    'CREATE TABLE "write-on-success" AS (SELECT \'hello hell\' AS someText);',
                                    'CREATE TABLE "write-always" AS (SELECT \'hello world\' AS someText);',
                                    'CRATE TABLE INTO A BOX',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    null,
                    $configurationData,
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Query "CRATE TABLE INTO A BOX" in "Main" failed with error',
                $e->getMessage(),
            );
        }

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.write-on-success'));

        // but the write-always table should exist
        self::assertTrue($this->client->tableExists('out.c-runner-test.write-always'));
    }

    public function testOutputTablesOnJobFailureOriginalErrorNotConcealed(): void
    {
        $this->clearFiles();
        $this->clearBuckets();

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
        $configurationData = [
            'storage' => [
                'output' => [
                    'files' => [],
                    'tables' => [
                        [
                            'source' => 'write-always.csv',
                            'destination' => 'out.c-runner-test.write-always',
                            'write_always' => true,
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import csv',
                    'import sys',
                    'import json',
                    'manifest = {"invalid": "true"}',
                    'out_file = open("out/tables/write-always.csv.manifest", "w")',
                    'json.dump(manifest, out_file, indent=2)',
                    'out_file.close()',
                    'print("Class 1 error", file=sys.stderr)',
                    'sys.exit(1)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'runner-configuration',
                    $configurationData,
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Class 1 error',
                $e->getMessage(),
            );
        }

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.write-always'));
    }

    public function testOutputTablesOnJobFailureRecoverableOutputMappingError(): void
    {
        $fileTag = 'texty.csv.gz';

        $this->clearFiles();
        $this->clearBuckets();

        $this->uploadTextTestFile($fileTag);

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
        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [
                        [
                            'tags' => [$fileTag],
                            'processed_tags' => ['processed'],
                        ],
                    ],
                ],
                'output' => [
                    'files' => [],
                    'tables' => [
                        [
                            'source' => 'write-always.csv',
                            'destination' => 'out.c-runner-test.write-always',
                            'write_always' => true,
                        ],
                        [
                            'source' => 'non-existent.csv',
                            'destination' => 'out.c-runner-test.non-existent',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import csv',
                    'import sys',
                    'with open("out/tables/write-always.csv", mode="wt", encoding="utf-8") as out_file:',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    '    writer = csv.DictWriter(out_file, fieldnames=["col1", "col2"], lineterminator="\n", delimiter=",", quotechar=\'"\')',
                    '    writer.writeheader()',
                    '    writer.writerow({\'col1\': \'hello\', \'col2\': \'world\'})',
                    'sys.exit(0)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'runner-configuration',
                    $configurationData,
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Table sources not found: "non-existent.csv"',
                $e->getMessage(),
            );
        }

        // table should not exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.non-existent'));

        // but the write-always table should exist
        self::assertTrue($this->client->tableExists('out.c-runner-test.write-always'));

        // check that the input file is not tagged as processed because the job failed
        $inputFileList = $this->client->listFiles((new ListFilesOptions())->setQuery(
            'tags:"docker-runner-test" AND tags:"processed"',
        ));
        self::assertCount(0, $inputFileList);
    }

    public function testOutputTablesOnJobFailureNonRecoverableOutputMappingError(): void
    {
        $this->clearFiles();
        $this->clearBuckets();

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
        $configurationData = [
            'storage' => [
                'output' => [
                    'files' => [],
                    'tables' => [
                        [
                            'source' => 'write-always.csv',
                            'destination' => 'out.c-runner-test.write-always',
                            'write_always' => true,
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'import sys',
                    'sys.exit(0)',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $outputs = [];
        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'runner-configuration',
                    $configurationData,
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
            self::fail('Must fail');
        } catch (UserExceptionInterface $e) {
            self::assertStringContainsString(
                'Table sources not found: "write-always.csv"',
                $e->getMessage(),
            );
        }
        // but the write-always table should exist
        self::assertFalse($this->client->tableExists('out.c-runner-test.write-always'));
    }

    private function uploadTextTestFile(string $tag): void
    {
        $dataDir = __DIR__ .'/../data/';
        $this->getClient()->uploadFile(
            $dataDir . 'texty.csv.gz',
            (new FileUploadOptions())->setTags(
                [
                    self::RUNNER_TEST_FILES_TAG,
                    $tag,
                ],
            ),
        );
        sleep(1);
    }

    /**
     * @param Output[] $outputs
     */
    private static function assertOutputsContainInputTableResult(array $outputs): void
    {
        self::assertCount(1, $outputs);

        $inputTables = $outputs[0]->getInputTableResult()?->getTables();
        self::assertNotNull($inputTables);

        /** @var TableInfo[] $inputTables */
        $inputTables = iterator_to_array($inputTables);
        self::assertCount(1, $inputTables);

        self::assertSame('in.c-runner-test.test', $inputTables[0]->getId());

        /** @var Column[] $columns */
        $columns = iterator_to_array($inputTables[0]->getColumns());
        self::assertCount(2, $columns);

        self::assertSame('id', $columns[0]->getName());
        self::assertSame('text', $columns[1]->getName());
    }

    /**
     * @param Output[] $outputs
     */
    private static function assertOutputsContainInputFileStateList(array $outputs, string $expectedFileTag): void
    {
        self::assertCount(1, $outputs);

        $inputFiles = $outputs[0]->getInputFileStateList()?->jsonSerialize();
        self::assertNotNull($inputFiles);
        unset($inputFiles[0]['lastImportId']);

        self::assertEquals(
            [
                [
                    'tags' => [
                        [
                            'name' => $expectedFileTag,
                        ],
                    ],
                ],
            ],
            $inputFiles,
        );
    }
}
