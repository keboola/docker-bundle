<?php

namespace Keboola\DockerBundle\Tests\Executor;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Metadata\Job;

class DebugModeTest extends BaseExecutorTest
{
    private function downloadFile($fileId)
    {
        $fileInfo = $this->getClient()->getFile($fileId, (new GetFileOptions())->setFederationToken(true));
        // Initialize S3Client with credentials from Storage API
        $target = $this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'downloaded-data.zip';
        $s3Client = new S3Client([
            'version' => '2006-03-01',
            'region' => $fileInfo['region'],
            'retries' => $this->getClient()->getAwsRetries(),
            'credentials' => [
                'key' => $fileInfo['credentials']['AccessKeyId'],
                'secret' => $fileInfo['credentials']['SecretAccessKey'],
                'token' => $fileInfo['credentials']['SessionToken'],
            ],
            'http' => [
                'decode_content' => false,
            ],
        ]);
        $s3Client->getObject(array(
            'Bucket' => $fileInfo['s3Path']['bucket'],
            'Key' => $fileInfo['s3Path']['key'],
            'SaveAs' => $target,
        ));
        return $target;
    }

    public function testDebugModeInline()
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 4; $i++) {
            $csv->writeRow([$i, $i * 100, '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);
        $jobExecutor = $this->getJobExecutor([], []);
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-executor-test.modified',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'plain' => 'not-secret',
                        'script' => [
                            'import csv',
                            'with open("/data/in/tables/source.csv", mode="rt", encoding="utf-8") as in_file, open("/data/out/tables/destination.csv", mode="wt", encoding="utf-8") as out_file:',
                            '   lazy_lines = (line.replace("\0", "") for line in in_file)',
                            '   reader = csv.DictReader(lazy_lines, dialect="kbc")',
                            '   writer = csv.DictWriter(out_file, dialect="kbc", fieldnames=reader.fieldnames)',
                            '   writer.writeheader()',
                            '   for row in reader:',
                            '      writer.writerow({"name": row["name"], "oldValue": row["oldValue"] + "ping", "newValue": row["newValue"] + "pong"})',
                        ],
                    ],
                ],
            ],
        ];

        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        // check that output mapping was not done
        try {
            $this->getClient()->getTableDataPreview('out.c-executor-test.modified');
            $this->fail('Table should not exist.');
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(2, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        self::assertContains('JobId:123456', $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertContains('keboola.python-transformation', $files[1]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation', $files[1]['tags']);
        self::assertContains('JobId:123456', $files[1]['tags']);
        self::assertContains('debug', $files[1]['tags']);
        self::assertGreaterThan(1000, $files[1]['sizeBytes']);

        $fileName = $this->downloadFile($files[1]['id']);
        $zipArchive = new \ZipArchive();
        $zipArchive->open($fileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode($config, true);
        self::assertEquals('not-secret', $config['parameters']['plain']);
        self::assertArrayHasKey('script', $config['parameters']);
        $tableData = $zipArchive->getFromName('in/tables/source.csv');
        $lines = explode("\n", trim($tableData));
        sort($lines);
        self::assertEquals(
            [
                "\"0\",\"0\",\"1000\"",
                "\"1\",\"100\",\"1000\"",
                "\"2\",\"200\",\"1000\"",
                "\"3\",\"300\",\"1000\"",
                "\"name\",\"oldValue\",\"newValue\"",
            ],
            $lines
        );
        $zipArchive->close();
        unlink($fileName);

        $fileName = $this->downloadFile($files[0]['id']);
        $zipArchive = new \ZipArchive();
        $zipArchive->open($fileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode($config, true);
        self::assertEquals('not-secret', $config['parameters']['plain']);
        self::assertArrayHasKey('script', $config['parameters']);
        $tableData = $zipArchive->getFromName('out/tables/destination.csv');
        $lines = explode("\n", trim($tableData));
        sort($lines);
        self::assertEquals(
            [
                "0,0ping,1000pong",
                "1,100ping,1000pong",
                "2,200ping,1000pong",
                "3,300ping,1000pong",
                "name,oldValue,newValue",
            ],
            $lines
        );
    }

    public function testDebugModeFailure()
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 4; $i++) {
            $csv->writeRow([$i, $i * 100, '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);
        $jobExecutor = $this->getJobExecutor([], []);
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-executor-test.modified',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'plain' => 'not-secret',
                        'script' => [
                            'import sys',
                            'print("Intentional error", file=sys.stderr)',
                            'exit(1)',
                        ],
                    ],
                ],
            ],
        ];

        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        try {
            $jobExecutor->execute($job);
            self::fail('Must throw exception');
        } catch (UserException $e) {
            self::assertContains('Intentional error', $e->getMessage());
        }

        // check that output mapping was not done
        try {
            $this->getClient()->getTableDataPreview('out.c-executor-test.modified');
            $this->fail('Table should not exist.');
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(1, count($files));
        self::assertEquals(0, strcasecmp('stage_0.zip', $files[0]['name']));
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation', $files[0]['tags']);
        self::assertContains('JobId:123456', $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);
    }

    public function testDebugModeConfiguration()
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 100; $i++) {
            $csv->writeRow([$i, '100', '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);
        $configuration = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'destination.csv',
                            'destination' => 'out.c-executor-test.modified',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'plain' => 'not-secret',
                '#encrypted' => $this->getEncryptorFactory()->getEncryptor()->encrypt('secret'),
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                    'contents = Path("/data/config.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination.csv")',
                ],
            ],
        ];
        $jobExecutor = $this->getJobExecutor($configuration, []);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => 'executor-configuration',
            ],
        ];

        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        // check that output mapping was not done
        try {
            $this->getClient()->getTableDataPreview('out.c-executor-test.modified');
            $this->fail('Table should not exist.');
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        // check that the component got deciphered values
        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == 400) {
                $output = $record['message'];
            }
        }
        $config = \GuzzleHttp\json_decode(strrev(base64_decode($output)), true);
        self::assertEquals('secret', $config['parameters']['#encrypted']);
        self::assertEquals('not-secret', $config['parameters']['plain']);

        // check that the files were stored
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(2, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertGreaterThan(2000, $files[0]['sizeBytes']);
        self::assertGreaterThan(2000, $files[1]['sizeBytes']);

        // check that the archive does not contain the decrypted value
        $zipFileName = $this->downloadFile($files[1]["id"]);
        $zipArchive = new \ZipArchive();
        $zipArchive->open($zipFileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode($config, true);
        self::assertNotEquals('secret', $config['parameters']['#encrypted']);
        self::assertEquals('[hidden]', $config['parameters']['#encrypted']);
        self::assertEquals('not-[hidden]', $config['parameters']['plain']);
    }

    public function testConfigurationRows()
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 100; $i++) {
            $csv->writeRow([$i, '100', '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => 'executor-configuration',
            ],
        ];
        $jobExecutor = $this->getJobExecutor([], $this->getConfigurationRows());
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->getClient()->getTableDataPreview('out.c-executor-test.transposed');
            $this->fail('Table should not exist.');
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(4, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertContains('RowId:row2', $files[0]['tags']);
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        self::assertContains('JobId:123456', $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1500, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertContains('RowId:row2', $files[1]['tags']);
        self::assertContains('keboola.python-transformation', $files[1]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation', $files[1]['tags']);
        self::assertContains('JobId:123456', $files[1]['tags']);
        self::assertContains('debug', $files[1]['tags']);
        self::assertGreaterThan(1500, $files[1]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_output.zip', $files[2]['name']));
        self::assertContains('RowId:row1', $files[2]['tags']);
        self::assertContains('keboola.python-transformation', $files[2]['tags']);
        self::assertContains('JobId:123456', $files[2]['tags']);
        self::assertContains('debug', $files[2]['tags']);
        self::assertGreaterThan(1500, $files[2]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[3]['name']));
        self::assertContains('RowId:row1', $files[3]['tags']);
        self::assertContains('keboola.python-transformation', $files[3]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation', $files[3]['tags']);
        self::assertContains('JobId:123456', $files[3]['tags']);
        self::assertContains('debug', $files[3]['tags']);
        self::assertGreaterThan(1500, $files[3]['sizeBytes']);
    }

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
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-executor-test.destination',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'script' => [
                            'from shutil import copyfile',
                            'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination.csv")',
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
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination-2.csv',
                                    'destination' => 'out.c-executor-test.destination-2',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'script' => [
                            'from shutil import copyfile',
                            'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination-2.csv")',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testConfigurationRowsProcessors()
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 100; $i++) {
            $csv->writeRow([$i, '100', '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => 'executor-configuration',
            ],
        ];

        $configurationRows = $this->getConfigurationRows();
        $configurationRows[0]['configuration']['processors'] = [
            'after' => [
                [
                    'definition' => [
                        'component' => 'keboola.processor-create-manifest'
                    ],
                    'parameters' => [
                       'columns_from' => 'header'
                    ],
                ],
                [
                    'definition' => [
                        'component' => 'keboola.processor-add-row-number-column'
                    ],
                ],
            ],
        ];
        $jobExecutor = $this->getJobExecutor([], $configurationRows);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->getClient()->getTableDataPreview('out.c-executor-test.transposed');
            $this->fail('Table should not exist.');
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(6, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertContains('RowId:row2', $files[0]['tags']);
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        self::assertContains('JobId:123456', $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertContains('RowId:row2', $files[1]['tags']);
        self::assertContains('keboola.python-transformation', $files[1]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation', $files[1]['tags']);
        self::assertContains('JobId:123456', $files[1]['tags']);
        self::assertContains('debug', $files[1]['tags']);
        self::assertGreaterThan(1000, $files[1]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_output.zip', $files[2]['name']));
        self::assertContains('RowId:row1', $files[2]['tags']);
        self::assertContains('keboola.python-transformation', $files[2]['tags']);
        self::assertContains('JobId:123456', $files[2]['tags']);
        self::assertContains('debug', $files[2]['tags']);
        self::assertGreaterThan(1000, $files[2]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_2.zip', $files[3]['name']));
        self::assertContains('RowId:row1', $files[3]['tags']);
        self::assertContains('keboola.processor-add-row-number-column', $files[3]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-add-row-number-column', $files[3]['tags']);
        self::assertContains('JobId:123456', $files[3]['tags']);
        self::assertContains('debug', $files[3]['tags']);
        self::assertGreaterThan(1000, $files[3]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_1.zip', $files[4]['name']));
        self::assertContains('RowId:row1', $files[4]['tags']);
        self::assertContains('keboola.processor-create-manifest', $files[4]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-create-manifest', $files[4]['tags']);
        self::assertContains('JobId:123456', $files[4]['tags']);
        self::assertContains('debug', $files[4]['tags']);
        self::assertGreaterThan(1000, $files[4]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[5]['name']));
        self::assertContains('RowId:row1', $files[5]['tags']);
        self::assertContains('keboola.python-transformation', $files[5]['tags']);
        self::assertContains('147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation', $files[5]['tags']);
        self::assertContains('JobId:123456', $files[5]['tags']);
        self::assertContains('debug', $files[5]['tags']);
        self::assertGreaterThan(1000, $files[5]['sizeBytes']);
    }
}
