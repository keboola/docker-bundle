<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Job\Metadata\Job;

class JobExecutorInlineConfigWithConfigIdTest extends BaseExecutorTest
{
    public function testRun()
    {
        $this->createBuckets();
        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
                'default_bucket_stage' => 'out',
            ],
        ];
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([['token' => STORAGE_API_TOKEN, 'url' => STORAGE_API_URL]])
            ->setMethods(['indexAction', 'verifyToken'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['services' => [['id' => 'oauth', 'url' => 'https://someurl']], 'components' => [$componentData]]));
        $clientMock->expects(self::any())
            ->method('verifyToken')
            ->willReturn(['owner' => ['id' => '321', 'name' => 'Name'], 'id' => '123', 'description' => 'Description']);
        $this->setClientMock($clientMock);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-test',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'input.csv',
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

        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);
        self::assertEquals(null, $ret['configVersion']);
        self::assertTrue($this->getClient()->tableExists('out.c-keboola-python-transformation-executor-test.result'));
    }
}
