<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ImageConfigurationTest extends TestCase
{
    public function testConfiguration()
    {
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
                'vendor' => ['a' => 'b'],
                'image_parameters' => ['foo' => 'bar'],
                'synchronous_actions' => ['test', 'test2'],
                'network' => 'none',
                'logging' => [
                    'type' => 'gelf',
                    'verbosity' => [200 => 'verbose'],
                    'no_application_errors' => true,
                ],
            ],
            'dataTypesConfiguration' => [
                'dataTypesSupport' => 'authoritative',
            ],
            'processorConfiguration' => [
                'allowedProcessorPosition' => 'before',
            ],
            'extraKey' => 'extraValue',
        ];
        $expectedConfiguration = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'latest',
                    'digest' => '',
                ],
                'memory' => '64m',
                'configuration_format' => 'json',
                'process_timeout' => 3600,
                'forward_token' => false,
                'forward_token_details' => false,
                'default_bucket' => false,
                'default_bucket_stage' => 'in',
                'vendor' => ['a' => 'b'],
                'image_parameters' => ['foo' => 'bar'],
                'synchronous_actions' => ['test', 'test2'],
                'network' => 'none',
                'logging' => [
                    'type' => 'gelf',
                    'verbosity' => [200 => 'verbose'],
                    'gelf_server_type' => 'tcp',
                    'no_application_errors' => true,
                ],
                'staging_storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
            'dataTypesConfiguration' => [
                'dataTypesSupport' => 'authoritative',
            ],
            'processorConfiguration' => [
                'allowedProcessorPosition' => 'before',
            ],
            'features' => [],
        ];
        $processedConfiguration = (new Configuration\Component())->parse(['config' => $config]);
        self::assertEquals($expectedConfiguration, $processedConfiguration);
    }

    public function testEmptyConfiguration()
    {
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
            ],
        ];
        $processedConfiguration = (new Configuration\Component())->parse(['config' => $config]);
        $expectedConfiguration = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'latest',
                    'digest' => '',
                ],
                'memory' => '256m',
                'configuration_format' => 'json',
                'process_timeout' => 3600,
                'forward_token' => false,
                'forward_token_details' => false,
                'default_bucket' => false,
                'synchronous_actions' => [],
                'default_bucket_stage' => 'in',
                'staging_storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
                'image_parameters' => [],
                'network' => 'bridge',
                'logging' => [
                    'type' => 'standard',
                    'verbosity' => [
                        100 => 'none',
                        200 => 'normal',
                        250 => 'normal',
                        300 => 'normal',
                        400 => 'normal',
                        500 => 'camouflage',
                        550 => 'camouflage',
                        600 => 'camouflage',
                    ],
                    'gelf_server_type' => 'tcp',
                    'no_application_errors' => false,
                ],
            ],
            'features' => [],
        ];
        self::assertEquals($expectedConfiguration, $processedConfiguration);
    }

    public function testWrongDefinitionType()
    {
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'whatever',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
            ],
        ];
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage(
            'Invalid configuration for path "component.data.definition.type": Invalid image type "whatever".',
        );
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testWrongConfigurationFormat()
    {
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
                'configuration_format' => 'fail',
            ],
        ];
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage(
            'Invalid configuration for path "component.data.configuration_format": ' .
            'Invalid configuration_format "fail".',
        );
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testExtraConfigurationField()
    {
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'unknown' => [],
            ],
        ];
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "unknown" under "component.data"');
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testWrongNetworkType()
    {
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
                'network' => 'whatever',
            ],
        ];
        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage(
            'Invalid configuration for path "component.data.network": Invalid network type "whatever".',
        );
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testWrongStagingInputStorageType()
    {
        $this->expectException('\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException');
        $this->expectExceptionMessage(
            'The value "whatever" is not allowed for path "component.data.staging_storage.input". ' .
            'Permissible values: "local", "s3", "abs", "none", "workspace-snowflake", ' .
            '"workspace-redshift", "workspace-synapse", "workspace-abs"',
        );
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
                'staging_storage' => [
                    'input' => 'whatever',
                ],
            ],
        ];
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testWrongStagingOutputStorageType()
    {
        $this->expectException('\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException');
        $this->expectExceptionMessage(
            'The value "whatever" is not allowed for path "component.data.staging_storage.output". ' .
            'Permissible values: "local", "none", "workspace-snowflake", ' .
            '"workspace-redshift", "workspace-synapse", "workspace-abs"',
        );
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
                'staging_storage' => [
                    'output' => 'whatever',
                ],
            ],
        ];
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testWrongDataTypesSupportValue(): void
    {
        $this->expectException('\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException');
        $this->expectExceptionMessage(
            'The value "whatever" is not allowed for path "component.dataTypesConfiguration.dataTypesSupport". ' .
            'Permissible values: "authoritative", "hints", "none"',
        );
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
            ],
            'dataTypesConfiguration' => [
                'dataTypesSupport' => 'whatever',
            ],
        ];
        (new Configuration\Component())->parse(['config' => $config]);
    }

    public function testWrongAllowedProcessorPositionValue(): void
    {
        $this->expectException('\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException');
        $this->expectExceptionMessage(
            'The value "whatever" is not allowed for path "component.processorConfiguration.allowedProcessorPosition".'.
            ' Permissible values: "any", "before", "after"',
        );
        $config = [
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                ],
                'memory' => '64m',
            ],
            'processorConfiguration' => [
                'allowedProcessorPosition' => 'whatever',
            ],
        ];
        (new Configuration\Component())->parse(['config' => $config]);
    }
}
