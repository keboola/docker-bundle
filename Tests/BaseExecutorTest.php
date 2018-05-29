<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;

abstract class BaseExecutorTest extends BaseRunnerTest
{
    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var Runner\
     */
    private $runnerStub;

    public function setUp()
    {
        parent::setUp();
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        $tokenData = $this->getClient()->verifyToken();
        $this->getEncryptorFactory()->setProjectId($tokenData['owner']['id']);
        $this->getEncryptorFactory()->setComponentId('keboola.python-transformation');
        $this->runnerStub = null;
    }

    protected function getTemp()
    {
        return $this->temp;
    }

    protected function clearBuckets()
    {
        foreach (['in.c-docker-test', 'out.c-docker-test'] as $bucket) {
            try {
                $this->getClient()->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    protected function createBuckets()
    {
        $this->clearBuckets();
        // Create buckets
        $this->getClient()->createBucket('docker-test', Client::STAGE_IN, 'Docker TestSuite');
    }

    protected function clearFiles()
    {
        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['debug', 'docker-bundle-test']);
        $files = $this->getClient()->listFiles($options);
        foreach ($files as $file) {
            $this->getClient()->deleteFile($file['id']);
        }
    }

    private function clearConfigurations()
    {
        $cmp = new Components($this->getClient());
        try {
            $cmp->deleteConfiguration('keboola.python-transformation', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    protected function setRunnerMock($runnerMock)
    {
        $this->runnerStub = $runnerMock;
    }

    protected function getJobExecutor(array $configuration, array $rows)
    {
        $this->clearConfigurations();
        if ($this->runnerStub) {
            $runner = $this->runnerStub;
        } else {
            $runner = $this->getRunner();
        }
        $componentService = new ComponentsService($this->getStorageService());
        $cmp = new Components($this->getClient());
        $cfg = new Configuration();
        $cfg->setComponentId('keboola.python-transformation');
        $cfg->setConfigurationId('test-configuration');
        $cfg->setConfiguration($configuration);
        $cfg->setName('Test configuration');
        $cmp->addConfiguration($cfg);
        foreach ($rows as $item) {
            $cfgRow = new ConfigurationRow($cfg);
            $cfgRow->setConfiguration($item['configuration']);
            $cfgRow->setRowId($item['id']);
            $cmp->addConfigurationRow($cfgRow);
        }

        $jobExecutor = new Executor(
            $this->getLoggersService()->getLog(),
            $runner,
            $this->getEncryptorFactory(),
            $componentService,
            STORAGE_API_URL
        );
        $jobExecutor->setStorageApi($this->getClient());

        return $jobExecutor;
    }

}
