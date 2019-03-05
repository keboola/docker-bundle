<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Components\ListConfigurationRowsOptions;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Temp\Temp;
use Monolog\Logger;

class RunnerTest extends BaseRunnerTest
{
    private function clearBuckets()
    {
        foreach (['in.c-runner-test', 'out.c-runner-test', 'in.c-keboola-docker-demo-sync-runner-configuration'] as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    private function clearConfigurations()
    {
        $cmp = new Components($this->getClient());
        try {
            $cmp->deleteConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    private function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('runner-test', Client::STAGE_IN, 'Docker TestSuite');
        $this->getClient()->createBucket('runner-test', Client::STAGE_OUT, 'Docker TestSuite');
    }

    private function clearFiles()
    {
        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['docker-runner-test']);
        $files = $this->getClient()->listFiles($options);
        foreach ($files as $file) {
            $this->getClient()->deleteFile($file['id']);
        }
    }

    /**
     * @param array $componentData
     * @param $configId
     * @param array $configData
     * @param array $state
     * @return JobDefinition[]
     */
    protected function prepareJobDefinitions(array $componentData, $configId, array $configData, array $state)
    {
        $jobDefinition = new JobDefinition($configData, new Component($componentData), $configId, 'v123', $state);
        return [$jobDefinition];
    }

    /**
     * Transform metadata into a key-value array
     * @param $metadata
     * @return array
     */
    private function getMetadataValues($metadata)
    {
        $result = [];
        foreach ($metadata as $item) {
            $result[$item['provider']][$item['key']] = $item['value'];
        }
        return $result;
    }

    public function testGetOauthUrl()
    {
        $clientMock = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->setMethods(['indexAction', 'verifyToken'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));
        $clientMock->expects(self::any())
            ->method('verifyToken')
            ->willReturn([]);
        $this->setClientMock($clientMock);
        $runner = $this->getRunner();

        $method = new \ReflectionMethod($runner, 'getOauthUrlV3');
        $method->setAccessible(true);
        $response = $method->invoke($runner);
        self::assertEquals($response, 'https://someurl');
    }

    public function testRunnerProcessors()
    {
        $this->clearBuckets();
        $this->clearFiles();
        $components = [
            [
                'id' => 'keboola.processor-last-file',
                'data' => [
                    'definition' => [
                      'type' => 'aws-ecr',
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
                        'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress',
                        'tag' => 'v4.1.0',
                    ],
                ],
            ],
        ];
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $dataDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
        $this->getClient()->uploadFile(
            $dataDir . 'texty.csv.gz',
            (new FileUploadOptions())->setTags(['docker-runner-test', 'texty.csv.gz'])
        );
        sleep(1);

        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [
                        [
                            'tags' => ['texty.csv.gz'],
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'texty.csv',
                            'destination' => 'out.c-runner-test.texty'
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/texty.csv.gz/texty.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/texty.csv", row.names = FALSE)',
                ],
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor-last-file',
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation',
                    'tag' => '1.2.8',
                ],
            ],
        ];
        $this->setClientMock($clientMock);
        $runner = $this->getRunner();
        $outputs = $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        self::assertEquals(
            [
                0 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-last-file:0.3.0',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-last-file@sha256:0c730bd4d91ca6962d72cd0d878a97857a1ef7c37eadd2eafd770ca26e627b0e'
                    ],
                ],
                1 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress:v4.1.0',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress@sha256:30a1a7119d51b5bb42d6c088fd3d98fed8ff7025fdca65618328face13bda91f'
                    ],
                ],
                2 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-move-files:v2.2.1',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-move-files@sha256:991ba73bb0fa8622c791eadc23b845aa74578fa136e328ea19b1305a530edded'
                    ],
                ],
                3 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv:4.0.0',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv@sha256:5c92ba8195dafe80455e59b99554155fd7095d59b1993e0dfc25ae44506e8be5'
                    ],
                ],
                4 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation:1.2.8',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation@sha256:e339e69841712bc8ef87f04020e244cbf237f206e6d6d2c1621c20e515b8562d'
                    ],
                ]
            ],
            $outputs[0]->getImages()
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

    public function testImageParametersDecrypt()
    {
        $configurationData = [
           'parameters' => [
               'foo' => 'bar',
               'script' => [
                   'import os',
                   'import sys',
                   'print(os.environ.get("KBC_TOKEN"))',
                   'print(os.environ.get("KBC_CONFIGID"))',
                   'with open("/data/config.json", "r") as file:',
                   '    print(file.read(), file=sys.stderr)',
               ],
           ],
        ];
        $encrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt('someString');

        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configId,
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $records = $this->getContainerHandler()->getRecords();
        $contents = '';
        $config = [];
        foreach ($records as $record) {
            if ($record['level'] == Logger::ERROR) {
                $config = \GuzzleHttp\json_decode($record['message'], true);
            } else {
                $contents .= $record['message'];
            }
        }

        // verify that the token is not passed by default
        self::assertNotContains(STORAGE_API_TOKEN, $contents);
        self::assertContains($configId, $contents);
        unset($config['parameters']['script']);
        self::assertEquals(['foo' => 'bar'], $config['parameters']);
        self::assertEquals(
            ['foo' => 'bar', 'baz' => ['lily' => 'pond'], '#encrypted' => '[hidden]'],
            $config['image_parameters']
        );
    }

    public function testClearStateWithoutNamespace()
    {
        $this->clearConfigurations();
        $state = ['key' => 'value'];
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                ['parameters' => ['script' => ['import os']]],
                $state
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        $cfg = $cmp->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([StateFile::NAMESPACE_PREFIX => []], $cfg['state']);
        $this->clearConfigurations();
    }

    public function testClearStateWithNamespace()
    {
        $this->clearConfigurations();
        $state = [StateFile::NAMESPACE_PREFIX => ['key' => 'value']];
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                ['parameters' => ['script' => ['import os']]],
                $state
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        $cfg = $cmp->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([StateFile::NAMESPACE_PREFIX => []], $cfg['state']);
        $this->clearConfigurations();
    }

    public function testExecutorDefaultBucketWithDot()
    {
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'test', $csv);
        $this->getClient()->setTableAttribute('in.c-runner-test.test', 'attr1', 'val1');
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
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/sliced.csv")',
                ]
            ]
        ];
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
                'default_bucket_stage' => 'out',
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        self::assertTrue($this->getClient()->tableExists('out.c-keboola-docker-demo-sync-runner-configuration.sliced'));
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Waiting for Storage jobs'));
        $this->clearBuckets();
    }

    public function testExecutorStoreState()
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
                    '   json.dump({"baz": "fooBar", "#encrypted": "secret"}, state_file)'
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertArrayHasKey('baz', $configuration['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertEquals('fooBar', $configuration['state'][StateFile::NAMESPACE_PREFIX]['baz']);
        self::assertArrayHasKey('#encrypted', $configuration['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertStringStartsWith('KBC::ProjectSecure::', $configuration['state'][StateFile::NAMESPACE_PREFIX]['#encrypted']);
        self::assertEquals(
            'secret',
            $this->getEncryptorFactory()->getEncryptor()->decrypt($configuration['state'][StateFile::NAMESPACE_PREFIX]['#encrypted'])
        );
        $this->clearConfigurations();
    }

    public function testExecutorReadLegacyState()
    {
        $state = ['foo' => 'bar'];
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
                    '   assert data["foo"] == "bar", json.dumps(data)'
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                $state
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        $this->clearConfigurations();
    }

    public function testExecutorReadNamespacedState()
    {
        $state = [StateFile::NAMESPACE_PREFIX => ['foo' => 'bar']];
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                $state
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorStoreStateWithProcessor()
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
                    '   json.dump({"baz": "fooBar"}, state_file)'
                ],
            ],
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-move-files'
                        ],
                        'parameters' => [
                            'direction' => 'tables'
                        ]
                    ]

                ]
            ]
        ];
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([StateFile::NAMESPACE_PREFIX => ['baz' => 'fooBar']], $configuration['state']);
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateRows()
    {
        $this->clearBuckets();
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_PREFIX => ["foo" => "bar"]]);
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            [
                new JobDefinition($configData1, new Component($componentData), 'runner-configuration', 'v123', [], 'row-1'),
                new JobDefinition($configData2, new Component($componentData), 'runner-configuration', 'v123', [], 'row-2'),
            ],
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $component = new Components($this->getClient());
        $listOptions = new ListConfigurationRowsOptions();
        $listOptions->setComponentId('keboola.docker-demo-sync')->setConfigurationId('runner-configuration');
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        // configuration state should be unchanged
        self::assertArrayHasKey('foo', $configuration['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertEquals('bar', $configuration['state'][StateFile::NAMESPACE_PREFIX]['foo']);
        $rows = $component->listConfigurationRows($listOptions);
        uasort(
            $rows,
            function ($a, $b) {
                return strcasecmp($a['id'], $b['id']);
            }
        );
        $row1 = $rows[0];
        $row2 = $rows[1];
        self::assertArrayHasKey('bazRow1', $row1['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertEquals('fooBar1', $row1['state'][StateFile::NAMESPACE_PREFIX]['bazRow1']);
        self::assertArrayHasKey('bazRow2', $row2['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertEquals('fooBar2', $row2['state'][StateFile::NAMESPACE_PREFIX]['bazRow2']);
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Running component keboola.docker-demo-sync (row 1 of 2)'));
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Running component keboola.docker-demo-sync (row 2 of 2)'));
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Waiting for Storage jobs'));
        self::assertTrue($this->client->tableExists('out.c-runner-test.my-table-1'));
        self::assertTrue($this->client->tableExists('out.c-runner-test.my-table-2'));
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateRowsOutputMappingEarlyError()
    {
        $this->clearBuckets();
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_PREFIX => ["foo" => "bar"]]);
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
        ];
        $configurationRow->setConfiguration($configData1);
        $component->addConfigurationRow($configurationRow);

        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $runner = $this->getRunner();
        try {
            $runner->run(
                [
                    new JobDefinition($configData1, new Component($componentData), 'runner-configuration', 'v123', [], 'row-1'),
                ],
                'run',
                'run',
                '1234567',
                new NullUsageFile()
            );
        } catch (UserException $e) {
            self::assertContains(
                'Cannot upload file \'out.c-runner-test.my-table-1.csv\' to table ' .
                '\'out.c-runner-test.my-table-1\' in Storage API: There are duplicate columns in CSV file: foo',
                $e->getMessage()
            );
        }

        $component = new Components($this->getClient());
        $listOptions = new ListConfigurationRowsOptions();
        $listOptions->setComponentId('keboola.docker-demo-sync')->setConfigurationId('runner-configuration');
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        // configuration state should be unchanged
        self::assertArrayHasKey('foo', $configuration['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertEquals('bar', $configuration['state'][StateFile::NAMESPACE_PREFIX]['foo']);
        $row = $component->listConfigurationRows($listOptions)[0];

        self::assertArrayNotHasKey('component', $row['state']);
        self::assertFalse($this->client->tableExists('out.c-runner-test.my-table-1'));
        self::assertFalse($this->getRunnerHandler()->hasInfoThatContains('Waiting for 1 storage jobs'));
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateRowsOutputMappingLateError()
    {
        $this->clearBuckets();
        $this->clearConfigurations();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_PREFIX => ["foo" => "bar"]]);
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $configurationRow->setState([StateFile::NAMESPACE_PREFIX => ['fooRow1' => 'barRow1']]);
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
        ];
        $configurationRow->setConfiguration($configData1);
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $configurationRow->setState([StateFile::NAMESPACE_PREFIX => ['fooRow2' => 'barRow2']]);
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
        ];
        $configurationRow->setConfiguration($configData2);
        $component->addConfigurationRow($configurationRow);

        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $runner = $this->getRunner();
        try {
            $runner->run(
                [
                    new JobDefinition($configData1, new Component($componentData), 'runner-configuration', 'v123', [], 'row-1'),
                    new JobDefinition($configData2, new Component($componentData), 'runner-configuration', 'v123', [], 'row-2'),
                ],
                'run',
                'run',
                '1234567',
                new NullUsageFile()
            );
        } catch (UserException $e) {
            self::assertContains(
                'Failed to process output mapping: Failed to load table "out.c-runner-test.my-table-1": ' .
                'Load error: odbc_execute(): SQL error: Number of columns in file (3) does not match that of the ' .
                'corresponding table (2)',
                $e->getMessage()
            );
        }

        $component = new Components($this->getClient());
        $listOptions = new ListConfigurationRowsOptions();
        $listOptions->setComponentId('keboola.docker-demo-sync')->setConfigurationId('runner-configuration');
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        // configuration state should be unchanged
        self::assertArrayHasKey('foo', $configuration['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertEquals('bar', $configuration['state'][StateFile::NAMESPACE_PREFIX]['foo']);
        $rows = $component->listConfigurationRows($listOptions);
        uasort(
            $rows,
            function ($a, $b) {
                return strcasecmp($a['id'], $b['id']);
            }
        );
        $row1 = $rows[0];
        $row2 = $rows[1];
        self::assertArrayNotHasKey('bazRow1', $row1['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertArrayNotHasKey('bazRow2', $row2['state'][StateFile::NAMESPACE_PREFIX]);
        self::assertTrue($this->client->tableExists('out.c-runner-test.my-table-1'));
        self::assertTrue($this->client->tableExists('out.c-runner-test.my-table-2'));
        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains('Waiting for Storage jobs'));
        $this->clearConfigurations();
    }

    public function testExecutorStoreStateWithProcessorError()
    {
        $this->clearConfigurations();
        $runner = $this->getRunner();
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setState([StateFile::NAMESPACE_PREFIX => ['foo' => 'bar']]);
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)'
                ],
            ],
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-move-files'
                        ],
                        // required parameter direction is missing
                    ]

                ]
            ]
        ];
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'runner-configuration',
                    $configData,
                    []
                ),
                'run',
                'run',
                '1234567',
                new NullUsageFile()
            );
            self::fail('Must fail with user error');
        } catch (UserException $e) {
            self::assertContains('child node "direction" at path "parameters" must be configured.', $e->getMessage());
        }

        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals([StateFile::NAMESPACE_PREFIX => ['foo' => 'bar']], $configuration['state'], 'State must not be changed');
        $this->clearConfigurations();
    }

    public function testExecutorAfterProcessorNoState()
    {
        $this->clearConfigurations();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $index = [
            'components' => [
                $componentData,
                [
                    'id' => 'keboola.processor-dumpy',
                    'data' => [
                        'definition' => [
                            'type' => 'aws-ecr',
                            'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                            'tag' => 'latest',
                        ],
                    ],
                ],
            ],
            'services' => [
                [
                    'id' => 'oauth', 'url' => 'https://someurl'
                ],
            ],
        ];
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue($index));
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
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $records = $this->getContainerHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $output = '';
        foreach ($records as $record) {
            $output .= $record['message'];
        }
        self::assertContains('files', $output);
        self::assertContains('tables', $output);
        self::assertNotContains('state', $output, "No state must've been passed to the processor");
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals(['bar' => 'Kochba'], $configuration['state'][StateFile::NAMESPACE_PREFIX], 'State must be changed');
    }

    public function testExecutorBeforeProcessorNoState()
    {
        $this->clearConfigurations();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $index = [
            'components' => [
                $componentData,
                [
                    'id' => 'keboola.processor-dumpy',
                    'data' => [
                        'definition' => [
                            'type' => 'aws-ecr',
                            'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                            'tag' => 'latest',
                        ],
                    ],
                ],
            ],
            'services' => [
                [
                    'id' => 'oauth', 'url' => 'https://someurl'
                ],
            ],
        ];
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue($index));
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
        $component = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.docker-demo-sync');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('runner-configuration');
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $records = $this->getContainerHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $output = '';
        foreach ($records as $record) {
            $output .= $record['message'];
        }
        self::assertContains('files', $output);
        self::assertContains('tables', $output);
        self::assertNotContains('state', $output, "No state must've been passed to the processor");
        $component = new Components($this->getClient());
        $configuration = $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
        self::assertEquals(['bar' => 'Kochba'], $configuration['state'][StateFile::NAMESPACE_PREFIX], 'State must be changed');
    }

    public function testExecutorNoStoreState()
    {
        $this->clearConfigurations();
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)'
                ],
            ],
        ];
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $component = new Components($this->getClient());
        self::expectException(ClientException::class);
        self:$this->expectExceptionMessage('Configuration runner-configuration not found');
        $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
    }

    public function testExecutorStateNoConfigId()
    {
        $this->clearConfigurations();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $configData = [
            'parameters' => [
                'script' => [
                    'import json',
                    'with open("/data/out/state.json", "w") as state_file:',
                    '   json.dump({"baz": "fooBar"}, state_file)'
                ],
            ],
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                '',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $component = new Components($this->getClient());
        self::expectException(ClientException::class);
        self:$this->expectExceptionMessage('Configuration runner-configuration not found');
        $component->getConfiguration('keboola.docker-demo-sync', 'runner-configuration');
    }

    public function testExecutorNoConfigIdNoMetadata()
    {
        $this->clearConfigurations();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                null,
                $configData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        $metadataApi = new Metadata($this->getClient());
        $bucketMetadata = $metadataApi->listBucketMetadata('in.c-keboola-docker-demo-sync');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'keboola.docker-demo-sync'
            ]
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));
    }

    public function testExecutorInvalidConfiguration()
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
            ]
        ];
        $runner = $this->getRunner();
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        self::expectException(UserException::class);
        self::expectExceptionMessage('Unrecognized option "filterByRunId');
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorDefaultBucketNoStage()
    {
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
        $csv = new CsvFile($temp->getTmpFolder() . '/upload.csv');
        $csv->writeRow(['id', 'text']);
        $csv->writeRow(['test', 'test']);
        $this->getClient()->createTableAsync('in.c-runner-test', 'test', $csv);
        $this->getClient()->setTableAttribute('in.c-runner-test.test', 'attr1', 'val1');
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        self::assertTrue($this->getClient()->tableExists('in.c-keboola-docker-demo-sync-runner-configuration.sliced'));
        $this->clearBuckets();
    }

    public function testExecutorSyncActionNoStorage()
    {
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
                'synchronous_actions' => [],
            ],
        ];

        self::expectException(UserException::class);
        self::expectExceptionMessage("No such file or directory: '/data/in/tables/source.csv'");
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
                []
            ),
            'some-sync-action',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorNoStorage()
    {
        $this->createBuckets();
        $temp = new Temp();
        $temp->initRunFolder();
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'staging_storage' => [
                    'input' => 'none'
                ],
                'default_bucket' => true,
            ],
        ];

        self::expectException(UserException::class);
        self::expectExceptionMessage("No such file or directory: '/data/in/tables/source.csv'");
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorApplicationError()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
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
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
            'keboola.python-transformation:latest container \'1234567-norunid--0-keboola-docker-demo-sync\'' .
            ' failed: (2) Class 2 error'
        );

        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorUserError()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        self::expectException(UserException::class);
        self::expectExceptionMessage('Class 1 error');
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorApplicationErrorDisabled()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        self::expectException(UserException::class);
        self::expectExceptionMessage('Class 2 error');
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorApplicationErrorDisabledButStillError()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/non-existent',
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

        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Cannot pull image');
        $runner->run(
            $this->prepareJobDefinitions($componentData, 'runner-configuration', $configurationData, []),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testExecutorInvalidInputMapping()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
                            'foo' => 'bar'
                        ]
                    ]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'in.c-runner-test.out'
                        ]
                    ]
                ]
            ]
        ];
        $runner = $this->getRunner();
        self::expectException(UserException::class);
        self::expectExceptionMessage('Unrecognized option "foo" under "container.storage.input.tables.0"');
        $runner->run($this->prepareJobDefinitions($componentData, 'runner-configuration', $config, []), 'run', 'run', '1234567', new NullUsageFile());
    }

    public function testExecutorInvalidInputMapping2()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
                                    'label' => 'id'
                                ],
                                [
                                    'value' => 'col1',
                                    'label' => 'col1'
                                ]
                            ]
                        ]
                    ]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'in.c-runner-test.out'
                        ]
                    ]
                ]
            ]
        ];
        $runner = $this->getRunner();
        self::expectException(UserException::class);
        self::expectExceptionMessage('Invalid type for path "container.storage.input.tables.0.columns.0".');
        $runner->run($this->prepareJobDefinitions($componentData, 'runner-configuration', $config, []), 'run', 'run', '1234567', new NullUsageFile());
    }

    public function testExecutorSlicedFilesWithComponentRootUserFeature()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
            'features' => [
                'container-root-user'
            ]
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
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $config,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        self::assertTrue($this->getClient()->tableExists('in.c-runner-test.mytable'));
        $lines = explode("\n", $this->getClient()->getTableDataPreview('in.c-runner-test.mytable'));
        sort($lines);
        self::assertEquals(
            [
                '',
                '"col1"',
                '"value1"',
                '"value2"'
            ],
            $lines
        );
    }

    public function testExecutorSlicedFilesWithoutComponentRootUserFeature()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $config,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
        self::assertTrue($this->getClient()->tableExists('in.c-runner-test.mytable'));
    }

    public function testPermissionsFailedWithoutContainerRootUserFeature()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        self::expectException(UserException::class);
        // touch: cannot touch '/data/out/tables/mytable.csv.gz/part1': Permission denied
        self::expectExceptionMessageRegExp('/Permission denied/');
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'runner-configuration',
                $config,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );
    }

    public function testAuthorizationDecrypt()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
                        'four' => 'anotherFoo'
                    ]
                ]
            ]
        ];
        $runner = $this->getRunner();
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $records = $this->getContainerHandler()->getRecords();
        $error = '';
        foreach ($records as $record) {
            if ($record['level'] === Logger::ERROR) {
                $error .= $record['message'];
            }
        }
        $config = \GuzzleHttp\json_decode($error, true);
        self::assertEquals('[hidden]', $config['parameters']['#one']);
        self::assertEquals('anotherBar', $config['parameters']['two']);
        self::assertEquals('[hidden]', $config['authorization']['oauth_api']['credentials']['#three']);
        self::assertEquals('anotherFoo', $config['authorization']['oauth_api']['credentials']['four']);
    }

    public function testTokenObfuscate()
    {
        $componentData = [
            'id' => 'keboola.docker-demo-sync',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
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
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567',
            new NullUsageFile()
        );

        $records = $this->getContainerHandler()->getRecords();
        $output = '';
        foreach ($records as $record) {
            $output .= $record['message'];
        }
        self::assertNotContains(STORAGE_API_TOKEN, $output);
        self::assertContains('[hidden]', $output);
    }

    public function testExecutorStoreUsage()
    {
        $this->clearConfigurations();
        $job = new Job($this->getEncryptorFactory()->getEncryptor());
        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'update'])
            ->getMock();
        $jobMapperStub->expects(self::once())
            ->method('get')
            ->with('987654')
            ->willReturn($job);
        $usageFile = new UsageFile();
        $usageFile->setJobMapper($jobMapperStub);
        $usageFile->setFormat('json');
        $usageFile->setJobId('987654');
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
        $jobDefinition = new JobDefinition($configData, new Component($componentData), 'runner-configuration');
        $runner = $this->getRunner();
        $runner->run([$jobDefinition], 'run', 'run', '987654', $usageFile);
        self::assertEquals([
            [
                'metric' => 'kB',
                'value' => 150
            ]
        ], $job->getUsage());
    }

    public function testExecutorStoreRowsUsage()
    {
        $this->clearConfigurations();
        $job = new Job($this->getEncryptorFactory()->getEncryptor());
        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['get', 'update'])
            ->getMock();
        $jobMapperStub->expects(self::atLeastOnce())
            ->method('get')
            ->with('987654')
            ->willReturn($job);
        $usageFile = new UsageFile();
        $usageFile->setFormat('json');
        $usageFile->setJobId('987654');
        $usageFile->setJobMapper($jobMapperStub);

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

        $jobDefinition1 = new JobDefinition($configData, new Component($componentData), 'runner-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition($configData, new Component($componentData), 'runner-configuration', null, [], 'row-2');
        $runner = $this->getRunner();
        $runner->run([$jobDefinition1, $jobDefinition2], 'run', 'run', '987654', $usageFile);
        self::assertEquals([
            [
                'metric' => 'kB',
                'value' => 150
            ],
            [
                'metric' => 'kB',
                'value' => 150
            ]
        ], $job->getUsage());
    }

    /**
     * @dataProvider swapFeatureProvider
     */
    public function testExecutorSwap($features)
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
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
            'features' => $features
        ];
        $configData = [
            'parameters' => [
                'script' => [],
            ],
        ];
        $jobDefinition = new JobDefinition($configData, new Component($componentData), 'runner-configuration');
        $runner = $this->getRunner();
        $output = $runner->run([$jobDefinition], 'run', 'run', '987654', $usageFile);
        self::assertCount(1, $output);
        self::assertEquals("Script file /data/script.py\nScript finished", $output[0]->getProcessOutput());
    }

    public function swapFeatureProvider()
    {
        return [
            [["no-swap"]],
            [[]],
        ];
    }
}
