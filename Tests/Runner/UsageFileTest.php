<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Defuse\Crypto\Key;
use Keboola\DockerBundle\Docker\Runner\UsageFile;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Temp\Temp;
use Keboola\Syrup\Elasticsearch\JobMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class UsageFileTest extends TestCase
{
    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var string
     */
    private $dataDir;

    /**
     * @var Filesystem
     */
    private $fs;

    public function setUp()
    {
        $this->temp = new Temp('runner-usage-file-test');
        $this->fs = new Filesystem;
        $this->dataDir = $this->temp->getTmpFolder();
        $this->fs->mkdir([
            $this->dataDir . '/out'
        ]);
    }

    public function tearDown()
    {
        $this->fs->remove($this->dataDir);
        $this->temp = null;
    }

    public function testStoreUsageWrongDataJson()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "random" under');

        // there should be "metric" key instead of "random"
        $usage = <<<JSON
[
  {
    "random": "API calls",
    "value": 150
  },
  {
    "metric": "kiloBytes",
    "value": 150
  }
]
JSON;
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile($this->dataDir, 'json', $jobMapperStub, 1);
        $usageFile->storeUsage();
    }

    public function testStoreUsageWrongDataYaml()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "random" under');

        // there should be "metric" key instead of "random"
        $usage = <<<YAML
- metric: kiloBytes
  value: 987
- random: API Calls
  value: 150
YAML;
        $this->fs->dumpFile($this->dataDir . '/out/usage.yml', $usage);

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile($this->dataDir, 'yaml', $jobMapperStub, 1);
        $usageFile->storeUsage();
    }

    public function testStoreUsageOk()
    {
        $usage = <<<JSON
[
  {
    "metric": "kiloBytes",
    "value": 150
  }
]
JSON;
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );

        $jobMapperStub
            ->expects(self::once())
            ->method('get')
            ->willReturn(new Job($encryptorFactory->getEncryptor()));

        $jobMapperStub
            ->expects(self::once())
            ->method('update')
            ->with(self::callback(function ($job) use ($usage) {
                /** @var $job Job */
                return $job->getUsage() === \json_decode($usage, true);
            }));

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile($this->dataDir, 'json', $jobMapperStub, 1);
        $usageFile->storeUsage();
    }

    public function testStoreUsageUnknownJob()
    {
        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Job not found');

        $usage = <<<JSON
[
  {
    "metric": "kiloBytes",
    "value": 150
  }
]
JSON;
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $jobMapperStub
            ->expects(self::once())
            ->method('get')
            ->willReturn(null);

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile($this->dataDir, 'json', $jobMapperStub, 1);
        $usageFile->storeUsage();
    }
}
