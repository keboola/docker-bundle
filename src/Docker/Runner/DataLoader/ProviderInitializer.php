<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\InputMapping\Staging\Scope;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\OutputMapping\Staging\StrategyFactory as OutputStrategyFactory;

class ProviderInitializer
{
    public static function initializeInputProviders(
        InputStrategyFactory $stagingFactory,
        $stagingStorageInput,
        $componentId,
        $configId,
        $tokenInfo,
        $dataDirectory
    ) {
        if (($stagingStorageInput === InputStrategyFactory::WORKSPACE_REDSHIFT) && $tokenInfo['owner']['hasRedshift']) {
            $stagingFactory->addProvider(
                new RedshiftWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    InputStrategyFactory::WORKSPACE_REDSHIFT => new Scope([Scope::TABLE_DATA]),
                ]
            );
        }
        if (($stagingStorageInput === InputStrategyFactory::WORKSPACE_SNOWFLAKE) && $tokenInfo['owner']['hasSnowflake']) {
            $stagingFactory->addProvider(
                new SnowflakeWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    InputStrategyFactory::WORKSPACE_SNOWFLAKE => new Scope([Scope::TABLE_DATA]),
                ]
            );
        }
        if (($stagingStorageInput === InputStrategyFactory::WORKSPACE_SYNAPSE) && $tokenInfo['owner']['hasSynapse']) {
            $stagingFactory->addProvider(
                new SynapseWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    InputStrategyFactory::WORKSPACE_SYNAPSE => new Scope([Scope::TABLE_DATA]),
                ]
            );
        }
        if (($stagingStorageInput === InputStrategyFactory::WORKSPACE_ABS) &&
            ($tokenInfo['owner']['fileStorageProvider'] === 'azure')
        ) {
            $stagingFactory->addProvider(
                new ABSWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    InputStrategyFactory::WORKSPACE_ABS => new Scope([Scope::FILE_DATA, Scope::TABLE_DATA]),
                ]
            );
        }
        $stagingFactory->addProvider(
            new LocalProvider($dataDirectory),
            [
                InputStrategyFactory::LOCAL => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_DATA, Scope::TABLE_METADATA]),
                // TABLE_DATA for ABS and S3 is bound to LocalProvider because it requires no provider at all
                InputStrategyFactory::S3 => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_DATA, Scope::TABLE_METADATA]),
                InputStrategyFactory::ABS => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_DATA, Scope::TABLE_METADATA]),
                InputStrategyFactory::WORKSPACE_REDSHIFT => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_METADATA]),
                InputStrategyFactory::WORKSPACE_SYNAPSE => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_METADATA]),
                InputStrategyFactory::WORKSPACE_SNOWFLAKE => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_METADATA]),
                InputStrategyFactory::WORKSPACE_ABS => new Scope([Scope::FILE_METADATA, Scope::TABLE_METADATA]),
            ]
        );
    }

    public static function initializeOutputProviders(
        OutputStrategyFactory $stagingFactory,
        $stagingStorageOutput,
        $componentId,
        $configId,
        $tokenInfo,
        $dataDirectory
    ) {
        if (($stagingStorageOutput === OutputStrategyFactory::WORKSPACE_REDSHIFT) && $tokenInfo['owner']['hasRedshift']) {
            $stagingFactory->addProvider(
                new RedshiftWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    OutputStrategyFactory::WORKSPACE_REDSHIFT => new Scope([Scope::TABLE_DATA]),
                ]
            );
        }
        if (($stagingStorageOutput === OutputStrategyFactory::WORKSPACE_SNOWFLAKE) && $tokenInfo['owner']['hasSnowflake']) {
            $stagingFactory->addProvider(
                new SnowflakeWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    OutputStrategyFactory::WORKSPACE_SNOWFLAKE => new Scope([Scope::TABLE_DATA]),
                ]
            );
        }
        if (($stagingStorageOutput === OutputStrategyFactory::WORKSPACE_SYNAPSE) && $tokenInfo['owner']['hasSynapse']) {
            $stagingFactory->addProvider(
                new SynapseWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    OutputStrategyFactory::WORKSPACE_SYNAPSE => new Scope([Scope::TABLE_DATA]),
                ]
            );
        }
        if (($stagingStorageOutput === OutputStrategyFactory::WORKSPACE_ABS) &&
            ($tokenInfo['owner']['fileStorageProvider'] === 'azure')
        ) {
            $stagingFactory->addProvider(
                new ABSWorkspaceProvider($stagingFactory->getClientWrapper()->getBasicClient(), $componentId, $configId),
                [
                    OutputStrategyFactory::WORKSPACE_ABS => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_DATA]),
                ]
            );
        }
        $stagingFactory->addProvider(
            new LocalProvider($dataDirectory),
            [
                OutputStrategyFactory::LOCAL => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_DATA, Scope::TABLE_METADATA]),
                OutputStrategyFactory::WORKSPACE_REDSHIFT => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_METADATA]),
                OutputStrategyFactory::WORKSPACE_SYNAPSE => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_METADATA]),
                OutputStrategyFactory::WORKSPACE_SNOWFLAKE => new Scope([Scope::FILE_DATA, Scope::FILE_METADATA, Scope::TABLE_METADATA]),
                OutputStrategyFactory::WORKSPACE_ABS => new Scope([Scope::TABLE_METADATA]),
            ]
        );
    }
}
