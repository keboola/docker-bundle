<?php

namespace Keboola\DockerBundle\Tests\Executor;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Metadata\Job;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;

class JobExecutorInlineConfigTest extends BaseExecutorTest
{
    private function getJobParameters()
    {
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
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
        ];

        return $data;
    }

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
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-docker-test", "source", $csv);

        $handler = new TestHandler();
        $data = $this->getJobParameters();
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);
        self::assertEquals(null, $ret['configVersion']);

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
        self::assertFalse($handler->hasWarningThatContains('Overriding component tag'));
    }

    public function testRunInvalidRowId()
    {
        $data = $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
                    'storage' => [],
                    'parameters' => [],
                ],
                'row' => [1, 2, 3]
            ],
        ];
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectException(UserException::class);
        self::expectExceptionMessage('Unsupported row value');
        $jobExecutor->execute($job);
    }

    public function testRunOAuthSecured()
    {
        $data = $this->getJobParameters();
        $data['params']['configData']['authorization']['oauth_api']['id'] = '12345';
        $data['params']['configData']['storage'] = [];
        $data['params']['configData']['parameters']['script'] = [
            'from pathlib import Path',
            'import sys',
            'contents = Path("/data/config.json").read_text()',
            'print(contents, file=sys.stderr)',
        ];

        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret'
            ]
        ];
        $credentialsEncrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt($credentials, ComponentProjectWrapper::class);

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        $runner = $this->getRunner();
        // inject mock OAuth client inside Runner
        $prop = new \ReflectionProperty($runner, 'oauthClient');
        $prop->setAccessible(true);
        $prop->setValue($runner, $oauthStub);
        $this->setRunnerMock($runner);
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == Logger::ERROR) {
                $output .= $record['message'];
            }
        }
        $expectedConfig = [
            'parameters' => $data['params']['configData']['parameters'],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#first' => '[hidden]',
                        'third' => 'fourth',
                        'fifth' => [
                            '#sixth' => '[hidden]',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
            'action' => 'run',
        ];
        $expectedConfigRaw = $expectedConfig;
        $expectedConfigRaw['authorization']['oauth_api']['credentials']['#first'] = 'topSecret';
        $expectedConfigRaw['authorization']['oauth_api']['credentials']['fifth']['#sixth'] = 'topSecret';
        self::assertEquals($expectedConfig, json_decode($output, true));
    }

    public function testRunOAuthObfuscated()
    {
        $data = $this->getJobParameters();
        $data['params']['component'] = 'keboola.python-transformation';
        $data['params']['configData']['authorization']['oauth_api']['id'] = '12345';
        $data['params']['configData']['storage'] = [];
        $data['params']['configData']['parameters']['script'] = [
            'from pathlib import Path',
            'import sys',
            'import base64',
            // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
            'contents = Path("/data/config.json").read_text()[::-1]',
            'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
        ];
        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret'
            ]
        ];
        $credentialsEncrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt($credentials, ComponentProjectWrapper::class);

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        // inject mock OAuth client inside Runner
        $runner = $this->getRunner();
        $prop = new \ReflectionProperty($runner, 'oauthClient');
        $prop->setAccessible(true);
        $prop->setValue($runner, $oauthStub);
        $this->setRunnerMock($runner);
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == Logger::ERROR) {
                $output .= $record['message'];
            }
        }
        $expectedConfig = [
            'parameters' => $data['params']['configData']['parameters'],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#first' => 'superDummySecret',
                        'third' => 'fourth',
                        'fifth' => [
                            '#sixth' => 'anotherTopSecret',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
            'action' => 'run',
        ];
        $this->assertEquals($expectedConfig, json_decode(strrev(base64_decode($output)), true));
    }

    public function testRunTag()
    {
        $this->createBuckets();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync("in.c-docker-test", "source", $csv);

        $data = $this->getJobParameters();
        $data['params']['tag'] = '1.1.12';
        $jobExecutor = $this->getJobExecutor([],[]);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

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
        self::assertTrue($this->getRunnerHandler()->hasWarning('Overriding component tag with: \'1.1.12\''));
    }

    public function testIncrementalTags()
    {
        $this->clearFiles();
        // Create file
        $root = $this->getTemp()->getTmpFolder();
        file_put_contents($root . '/upload', 'test');

        $id1 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'toprocess'])
        );
        $id2 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'toprocess'])
        );
        $id3 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'incremental-test'])
        );

        $data = $this->getJobParameters();
        $data['params']['configData']['storage'] = [
            'input' => [
                'files' => [
                    [
                        'query' => 'tags: toprocess AND NOT tags: downloaded',
                        'processed_tags' => [
                            'downloaded',
                            'experimental',
                        ],
                    ],
                ],
            ],
        ];
        $data['params']['configData']['parameters'] = [
            'script' => [
                'from shutil import copyfile',
                'import ntpath',
                'import json',
                'for filename in os.listdir("/data/in/files/"):',
                '   if not filename.endswith(".manifest"):',
                '       print("ntp" + filename)',
                '       copyfile("/data/in/files/" + filename, "/data/out/files/" + filename)',
                '       with open("/data/out/files/" + filename + ".manifest", "w") as outfile:',
                '           data = {"tags": ["docker-bundle-test", "processed"]}',
                '           json.dump(data, outfile)',
            ],
        ];
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['downloaded']);
        $files = $this->getClient()->listFiles($listFileOptions);
        $ids = [];
        foreach ($files as $file) {
            $ids[] = $file['id'];
        }
        self::assertContains($id1, $ids);
        self::assertContains($id2, $ids);
        self::assertNotContains($id3, $ids);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['processed']);
        $files = $this->getClient()->listFiles($listFileOptions);
        self::assertEquals(2, count($files));
    }
}
