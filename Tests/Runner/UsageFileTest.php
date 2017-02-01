<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\UsageFile;
use Keboola\Temp\Temp;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class UsageFileTest extends \PHPUnit_Framework_TestCase
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

        $usageFile = new UsageFile($this->dataDir, 'yaml', $jobMapperStub, 1);
        $usageFile->storeUsage();
    }
}
