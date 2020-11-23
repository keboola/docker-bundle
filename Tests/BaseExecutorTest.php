<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Temp\Temp;

abstract class BaseExecutorTest extends BaseRunnerTest
{
    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var JobMapper
     */
    private $jobMapperStub;

    /**
     * @var StorageApiService
     */
    private $storageServiceStub;

    /** @var int */
    public $branchId;

    public function setUp()
    {
        parent::setUp();
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        $tokenData = $this->getClient()->verifyToken();
        $this->getEncryptorFactory()->setProjectId($tokenData['owner']['id']);
        $this->getEncryptorFactory()->setComponentId('keboola.python-transformation');
        $this->storageServiceStub = null;
    }

    protected function getTemp()
    {
        return $this->temp;
    }

    protected function clearBuckets()
    {
        foreach (['in.c-executor-test', 'out.c-executor-test', 'out.c-keboola-python-transformation-executor-test'] as $bucket) {
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
        $this->getClient()->createBucket('executor-test', Client::STAGE_IN, 'Docker TestSuite');
    }

    protected function clearFiles()
    {
        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['debug', 'executor-test']);
        $files = $this->getClient()->listFiles($options);
        foreach ($files as $file) {
            $this->getClient()->deleteFile($file['id']);
        }
    }

    private function clearConfigurations()
    {
        $cmp = new Components($this->getClient());
        try {
            $cmp->deleteConfiguration('keboola.python-transformation', 'executor-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }


    protected function getStorageService()
    {
        return $this->storageServiceStub;
    }

    protected function getJobExecutor(array $configuration, array $rows, array $state = [], $disableConfig = false, $branchName = '')
    {
        if (!$disableConfig) {
            $this->clearConfigurations();
        }
        if (!$this->jobMapperStub) {
            $this->jobMapperStub = self::getMockBuilder(JobMapper::class)
                ->disableOriginalConstructor()
                ->getMock();
        }
        if ($this->clientMock) {
            $storageClientStub = $this->clientMock;
        } else {
            $storageClientStub = $this->client;
        }
        $this->storageServiceStub = self::getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->storageServiceStub->expects(self::any())
            ->method("getClient")
            ->will(self::returnValue($storageClientStub));
        $this->storageServiceStub->expects(self::any())
            ->method("getTokenData")
            ->will(self::returnValue($storageClientStub->verifyToken()));
        $this->storageServiceStub->expects(self::any())
            ->method('getStepPollDelayFunction')
            ->will(self::returnValue(null));

        $componentService = new ComponentsService($this->getStorageService());
        if ($branchName) {
            $client = new Client(
                [
                    'url' => STORAGE_API_URL,
                    'token' => STORAGE_API_TOKEN_MASTER,
                ]
            );
            $tokenInfo = $client->verifyToken();
            print(sprintf(
                'Authorized as "%s (%s)" to project "%s (%s)" at "%s" stack.',
                $tokenInfo['description'],
                $tokenInfo['id'],
                $tokenInfo['owner']['name'],
                $tokenInfo['owner']['id'],
                $client->getApiUrl()
            ));

            $branches = new DevBranches($client);
            $branchList = $branches->listBranches();
            foreach ($branchList as $branch) {
                if ($branch['name'] === $branchName) {
                    $branchId = $branch['id'];
                    $branches->deleteBranch($branchId);
                    break;
                }
            }
            try {
                $cmpMain = new Components($client);
                $cmpMain->deleteConfiguration('keboola.python-transformation', 'executor-configuration');
            } catch (ClientException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
            $this->branchId = $branches->createBranch($branchName)['id'];
            $branchClient = new BranchAwareClient($this->branchId, [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]);

            $cmp = new Components($branchClient);
            $cfg = new Configuration();
            if (!$disableConfig) {
                $cfg->setComponentId('keboola.python-transformation');
                $cfg->setConfigurationId('executor-configuration');
                $cfg->setConfiguration($configuration);
                $cfg->setState($state);
                $cfg->setName('Test configuration');
                $cmp->addConfiguration($cfg);
                foreach ($rows as $item) {
                    $cfgRow = new ConfigurationRow($cfg);
                    $cfgRow->setConfiguration($item['configuration']);
                    $cfgRow->setRowId($item['id']);
                    $cfgRow->setIsDisabled($item['isDisabled']);
                    $cmp->addConfigurationRow($cfgRow);
                }
            }
        } else {
            $cmp = new Components($this->getClient());
            $cfg = new Configuration();
            if (!$disableConfig) {
                $cfg->setComponentId('keboola.python-transformation');
                $cfg->setConfigurationId('executor-configuration');
                $cfg->setConfiguration($configuration);
                $cfg->setState($state);
                $cfg->setName('Test configuration');
                $cmp->addConfiguration($cfg);
                foreach ($rows as $item) {
                    $cfgRow = new ConfigurationRow($cfg);
                    $cfgRow->setConfiguration($item['configuration']);
                    $cfgRow->setRowId($item['id']);
                    $cfgRow->setIsDisabled($item['isDisabled']);
                    $cmp->addConfigurationRow($cfgRow);
                }
            }
        }

        $jobExecutor = new Executor(
            $this->getLoggersService(),
            $this->getEncryptorFactory(),
            $componentService,
            STORAGE_API_URL,
            $this->storageServiceStub,
            $this->jobMapperStub,
            'dummy',
            ['cpu_count' => 2]
        );
        $jobExecutor->setStorageApi($this->getStorageService()->getClient());

        return $jobExecutor;
    }
}
