<?php

namespace Keboola\DockerBundle\Tests\JobExecutorTest;

use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentWrapper;
use Keboola\Syrup\Job\Metadata\Job;
use Monolog\Logger;

class EncryptionTest extends BaseExecutorTest
{
    public function testStoredConfigDecryptEncryptComponent()
    {
        $configuration = [
            'parameters' => [
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                    'contents = Path("/data/config.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                ],
                'key1' => 'first',
                '#key2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('second'),
                '#key3' => $this->getEncryptorFactory()->getEncryptor()->encrypt('third', ComponentWrapper::class),
                '#key4' => $this->getEncryptorFactory()->getEncryptor()->encrypt('fourth', ComponentProjectWrapper::class),
            ],
        ];

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
        $jobExecutor->execute($job);

        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == Logger::ERROR) {
                $output = $record['message'];
            }
        }
        $config = json_decode(strrev(base64_decode($output)), true);
        self::assertEquals('first', $config['parameters']['key1']);
        self::assertEquals('second', $config['parameters']['#key2']);
        self::assertEquals('third', $config['parameters']['#key3']);
        self::assertEquals('fourth', $config['parameters']['#key4']);
    }

    public function testStoredConfigRowDecryptEncryptComponent()
    {
        $configuration = [
            'parameters' => [
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str))
                    'contents = Path("/data/config.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                ],
                'configKey1' => 'first',
                '#configKey2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('second'),
                '#configKey3' => $this->getEncryptorFactory()->getEncryptor()->encrypt('third', ComponentWrapper::class),
                '#configKey4' => $this->getEncryptorFactory()->getEncryptor()->encrypt('fourth', ComponentProjectWrapper::class),
            ],
        ];
        $rows = [
            [
                'id' => 'row-1',
                'configuration' => [
                    'parameters' => [
                        'rowKey1' => 'value1',
                        '#rowKey2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('value2'),
                        '#rowKey3' => $this->getEncryptorFactory()->getEncryptor()->encrypt('value3', ComponentWrapper::class),
                        '#rowKey4' => $this->getEncryptorFactory()->getEncryptor()->encrypt('value4', ComponentProjectWrapper::class),
                    ],
                ],
            ],
        ];

        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'test-configuration'
            ]
        ];

        $jobExecutor = $this->getJobExecutor($configuration, $rows);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == Logger::ERROR) {
                $output = $record['message'];
            }
        }
        $config = json_decode(strrev(base64_decode($output)), true);
        self::assertEquals('first', $config['parameters']['configKey1']);
        self::assertEquals('second', $config['parameters']['#configKey2']);
        self::assertEquals('third', $config['parameters']['#configKey3']);
        self::assertEquals('fourth', $config['parameters']['#configKey4']);
        self::assertEquals('value1', $config['parameters']['rowKey1']);
        self::assertEquals('value2', $config['parameters']['#rowKey2']);
        self::assertEquals('value3', $config['parameters']['#rowKey3']);
        self::assertEquals('value4', $config['parameters']['#rowKey4']);
    }
}
