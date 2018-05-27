<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;

abstract class BaseExecutorTest extends BaseRunnerTest
{
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

    protected function getJobExecutor(array $configuration, array $rows)
    {
        $this->clearConfigurations();
        $runner = $this->getRunner();
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
