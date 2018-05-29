<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Job\Metadata\Job;

class JobExecutorStoredConfigMultipleRowsTest extends BaseExecutorTest
{
    private function getConfigurationRows()
    {
        return [
            [
                'id' => 'row1',
                'isDisabled' => false,
                'configuration' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-docker-test.source',
                                    'destination' => 'input.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'result.csv',
                                    'destination' => 'out.c-docker-test.output',
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
                ],
            ],
            [
                'id' => 'row2',
                'isDisabled' => false,
                'configuration' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-docker-test.source',
                                    'destination' => 'input.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'result.csv',
                                    'destination' => 'out.c-docker-test.output-2',
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
                ],
            ],
        ];
    }

    private function getJobParameters($rowId = null)
    {
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'test-configuration',
            ],
        ];

        if ($rowId) {
            $data['params']['row'] = $rowId;
        }

        return $data;
    }

    public function testRun()
    {
        $this->createBuckets();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-docker-test", "source", $csv);

        $data = $this->getJobParameters();
        $jobExecutor = $this->getJobExecutor([], $this->getConfigurationRows());
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);

        $csvData = $this->getClient()->getTableDataPreview(
            'out.c-docker-test.output',
            [
                'limit' => 1000,
            ]
        );
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

        $csvData = $this->getClient()->getTableDataPreview(
            'out.c-docker-test.output-2',
            [
                'limit' => 1000,
            ]
        );
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

    public function testRunOneRow()
    {
        $this->createBuckets();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-docker-test", "source", $csv);

        $data = $this->getJobParameters('row1');
        $jobExecutor = $this->getJobExecutor([], $this->getConfigurationRows());
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);

        $csvData = $this->getClient()->getTableDataPreview('out.c-docker-test.output');
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
        self::assertEquals(2, count($data));
        self::assertFalse($this->getClient()->tableExists('out.c-docker-test.transposed-2'));
    }

    public function testRunRowsDisabled()
    {
        $this->createBuckets();
        $rows = $this->getConfigurationRows();
        $rows[0]['isDisabled'] = true;
        unset($rows[1]);
        $data = $this->getJobParameters();
        $jobExecutor = $this->getJobExecutor([], $rows);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertEquals('No configurations executed.', $ret['message']);
        self::assertArrayHasKey('images', $ret);
        self::assertEquals([], $ret['images']);
        self::assertArrayHasKey('configVersion', $ret);
        self::assertEquals(null, $ret['configVersion']);
    }
}
