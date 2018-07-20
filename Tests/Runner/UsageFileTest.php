<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\UsageFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
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
        $this->fs->mkdir($this->dataDir . '/out');
    }

    public function testStoreUsageBadInit()
    {
        $usageFile = new UsageFile();
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Usage file not initialized.');
        $usageFile->storeUsage();
    }

    public function testStoreUsageWrongDataJson()
    {
        // there should be "metric" key instead of "random"
        $usage = \GuzzleHttp\json_encode([[
            'random' => 'API calls',
            'value' => 150,
        ]]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setJobMapper($jobMapperStub);
        $usageFile->setJobId(1);
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "random" under');
        $usageFile->storeUsage();
    }

    public function testStoreUsageWrongDataYaml()
    {
        // there should be "metric" key instead of "random"
        $usage = <<<YAML
- metric: kiloBytes
  value: 987
- random: API Calls
  value: 150
YAML;
        $this->fs->dumpFile($this->dataDir . '/out/usage.yml', $usage);

        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('yaml');
        $usageFile->setJobMapper($jobMapperStub);
        $usageFile->setJobId(1);
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "random" under');
        $usageFile->storeUsage();
    }

    public function testStoreUsageOk()
    {
        $usage = \GuzzleHttp\json_encode([[
            'metric' => 'kiloBytes',
            'value' => 150,
        ]]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
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
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setJobMapper($jobMapperStub);
        $usageFile->setJobId(1);
        $usageFile->storeUsage();
    }

    public function testStoreUsageUnknownJob()
    {
        $usage = \GuzzleHttp\json_encode([[
            'metric' => 'kiloBytes',
            'value' => 150,
        ]]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $jobMapperStub
            ->expects(self::once())
            ->method('get')
            ->willReturn(null);

        /** @var JobMapper $jobMapperStub */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setJobMapper($jobMapperStub);
        $usageFile->setJobId(1);
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Job not found');
        $usageFile->storeUsage();
    }
}
