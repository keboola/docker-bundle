<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\StorageApi\Client;
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
        ];
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-docker-test", "source", $csv);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'test-configuration',
            ],
        ];
        $jobExecutor = $this->getJobExecutor($configuration, []);
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
    }
}
