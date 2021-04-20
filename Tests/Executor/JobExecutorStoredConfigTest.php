<?php

namespace Keboola\DockerBundle\Tests\Executor;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Metadata\Job;

class JobExecutorStoredConfigTest extends BaseExecutorTest
{
    public function testRun()
    {
        $this->createBuckets();
        $configuration = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'input.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'result.csv',
                            'destination' => 'out.c-executor-test.output',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                ],
            ],
        ];
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-executor-test", "source", $csv);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
            ],
        ];
        $jobExecutor = $this->getJobExecutor($configuration, []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);

        $csvData = $this->getClient()->getTableDataPreview('out.c-executor-test.output');
        $data = Client::parseCsv($csvData);
        usort($data, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        self::assertEquals(
            [
                [
                    'name' => 'price',
                    'oldValue' => '100',
                    'newValue' => '1000',
                ],
                [
                    'name' =>  'size',
                    'oldValue' => 'small',
                    'newValue' => 'big',
                ],
            ],
            $data
        );
    }

    public function testRunBranch()
    {
        $this->createBuckets();
        $configuration = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'input.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'result.csv',
                            'destination' => 'out.c-executor-test.output',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                ],
            ],
        ];
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-executor-test", "source", $csv);

        $jobExecutor = $this->getJobExecutor($configuration, [], [], false, 'my-dev-branch');
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
                'branchId' => $this->branchId,
            ],
        ];
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);

        $csvData = $this->getClient()->getTableDataPreview(sprintf('out.c-%s-executor-test.output', $this->branchId));
        $data = Client::parseCsv($csvData);
        usort($data, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        self::assertEquals(
            [
                [
                    'name' => 'price',
                    'oldValue' => '100',
                    'newValue' => '1000',
                ],
                [
                    'name' =>  'size',
                    'oldValue' => 'small',
                    'newValue' => 'big',
                ],
            ],
            $data
        );
    }

    protected function initStorageClient()
    {
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]
        );
    }

    public function testRunBranchInvalid()
    {
        $this->createBuckets();
        $configuration = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'input.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'result.csv',
                            'destination' => 'out.c-executor-test.output',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                ],
            ],
        ];
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
                'branchId' => 'my-non-existent-branch',
            ],
        ];
        $jobExecutor = $this->getJobExecutor($configuration, []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectException(UserException::class);
        self::expectExceptionMessage('Error reading configuration \'executor-configuration\': Branch id "my-non-existent-branch" does not exist');
        $jobExecutor->execute($job);
    }

    /**
     * @dataProvider tagOverrideTestDataProvider
     */
    public function testTagOverride($storedConfigTag, $requestParamsTag, $expectedVersion)
    {
        $storedConfig = [
            'parameters' => [
                'script' => [
                    'print("Hello world!")',
                ],
            ],
        ];

        if ($storedConfigTag !== null) {
            $storedConfig['runtime']['image_tag'] = $storedConfigTag;
        }

        $requestData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
            ],
        ];
        if ($requestParamsTag !== null) {
            $requestData['params']['tag'] = $requestParamsTag;
        }

        $jobExecutor = $this->getJobExecutor($storedConfig, []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $requestData);
        $job->setId(123456);
        $jobExecutor->execute($job);

        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains(
            sprintf('Using component tag: "%s"', $expectedVersion)
        ));
    }

    /**
     * @return \Generator
     */
    public function tagOverrideTestDataProvider()
    {
        yield 'no override' => [
            'storedConfigTag' => null,
            'requestParamsTag' => null,
            'expectedVersion' => '1.4.0',
        ];

        yield 'stored config' => [
            'storedConfigTag' => '1.2.5',
            'requestParamsTag' => null,
            'expectedVersion' => '1.2.5',
        ];

        yield 'request params' => [
            'storedConfigTag' => null,
            'requestParamsTag' => '1.2.7',
            'expectedVersion' => '1.2.7',
        ];

        yield 'all ways' => [
            'storedConfigTag' => '1.2.5',
            'requestParamsTag' => '1.2.7',
            'expectedVersion' => '1.2.7',
        ];
    }
}
