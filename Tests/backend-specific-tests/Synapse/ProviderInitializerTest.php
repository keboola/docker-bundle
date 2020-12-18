<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\DataLoader\ProviderInitializer;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;
use Keboola\OutputMapping\Writer\File\Strategy\ABSWorkspace as OutputFileAbs;
use Keboola\OutputMapping\Writer\File\Strategy\Local as OutputFileLocal;
use Keboola\OutputMapping\Writer\Table\Strategy\AllEncompassingTableStrategy;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\NullLogger;

class ProviderInitializerTest extends BaseRunnerTest
{

    public function testInitializeOutputAbs()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self::markTestSkipped('Synapse test disabled.');
        }
        $stagingFactory = new OutputStrategyFactory(
            new ClientWrapper(
                new Client(['token' => STORAGE_API_TOKEN_SYNAPSE, 'url' => STORAGE_API_URL_SYNAPSE]),
                null,
                new NullLogger(),
                ''
            ),
            new NullLogger(),
            'json'
        );

        $components = new Components($stagingFactory->getClientWrapper()->getBasicClient());
        try {
            $components->deleteConfiguration('keboola.runner-workspace-abs-test', 'my-test-config');
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $configuration = new Configuration();
        $configuration->setConfigurationId('my-test-config');
        $configuration->setName($configuration->getConfigurationId());
        $configuration->setComponentId('keboola.runner-workspace-abs-test');
        $components->addConfiguration($configuration);
        $initializer = new ProviderInitializer();
        $initializer->initializeOutputProviders(
            $stagingFactory,
            OutputStrategyFactory::WORKSPACE_ABS,
            'keboola.runner-workspace-abs-test',
            'my-test-config',
            [
                'owner' => [
                    'hasSynapse' => true,
                    'hasRedshift' => true,
                    'hasSnowflake' => true,
                    'fileStorageProvider' => 'azure',
                ],
            ],
            '/tmp/random/data'
        );
        self::assertInstanceOf(OutputFileLocal::class, $stagingFactory->getFileOutputStrategy(OutputStrategyFactory::LOCAL));
        self::assertInstanceOf(AllEncompassingTableStrategy::class, $stagingFactory->getTableOutputStrategy(OutputStrategyFactory::LOCAL));
        self::assertInstanceOf(OutputFileAbs::class, $stagingFactory->getFileOutputStrategy(OutputStrategyFactory::WORKSPACE_ABS));
        self::assertInstanceOf(AllEncompassingTableStrategy::class, $stagingFactory->getTableOutputStrategy(OutputStrategyFactory::WORKSPACE_ABS));

        self::expectExceptionMessage('The project does not support "workspace-snowflake" table output backend.');
        self::expectException(InvalidOutputException::class);
        $stagingFactory->getTableOutputStrategy(OutputStrategyFactory::WORKSPACE_SNOWFLAKE);
    }
}
