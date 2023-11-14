<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Exception\UserException;
use SensitiveParameter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigFile
{
    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var string
     */
    private $format;

    /**
     * @var Authorization
     */
    private $authorization;

    /**
     * @var string
     */
    private $action;

    public function __construct(
        $dataDirectory,
        Authorization $authorization,
        $action,
        $format,
    ) {
        $this->dataDirectory = $dataDirectory;
        $this->format = $format;
        $this->authorization = $authorization;
        $this->action = $action;
    }

    public function createConfigFile(
        $configData,
        OutputFilterInterface $outputFilter,
        array $workspaceCredentials,
        array $imageParameters,
    ) {
        // create configuration file injected into docker
        $adapter = new Adapter($this->format);
        try {
            $backendContext = $configData['runtime']['backend']['context'] ?? null;
            // remove runtime parameters and processors which are not supposed to be passed into the container
            unset($configData['runtime']);
            unset($configData['processors']);

            $this->checkImageParametersMisuse($imageParameters, $configData);

            $configData['image_parameters'] = $imageParameters;
            if (!empty($configData['authorization'])) {
                $configData['authorization'] = $this->authorization->getAuthorization($configData['authorization']);
            } else {
                unset($configData['authorization']);
            }
            if (empty($configData['storage'])) {
                unset($configData['storage']);
            }
            if ($workspaceCredentials) {
                $configData['authorization']['workspace'] = $workspaceCredentials;
            }
            if ($backendContext) {
                $configData['authorization']['context'] = $backendContext;
            }

            // action
            $configData['action'] = $this->action;

            $fileName = $this->dataDirectory . DIRECTORY_SEPARATOR . 'config' . $adapter->getFileExtension();
            $outputFilter->collectValues($configData);
            $adapter->setConfig($configData);
            $adapter->writeToFile($fileName);
        } catch (InvalidConfigurationException $e) {
            throw new UserException('Error in configuration: ' . $e->getMessage(), $e);
        }
    }

    private function checkImageParametersMisuse(
        #[SensitiveParameter]
        array $imageParameters,
        #[SensitiveParameter]
        array $configData,
    ): void {
        $secretValues = [];
        array_walk_recursive(
            $imageParameters,
            function ($value, $key) use (&$secretValues) {
                if (str_starts_with((string) $key, '#')) {
                    $secretValues[] = (string) $value;
                }
            },
        );

        if (count($secretValues) === 0) {
            return;
        }

        $this->checkSecretsValueMisuse($configData, $secretValues);
    }

    private function checkSecretsValueMisuse(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        array $secretValues,
        array $path = [],
    ): void {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->checkSecretsValueMisuse($value, $secretValues, [...$path, $key]);
                continue;
            }

            if (!str_starts_with((string) $key, '#')) {
                continue;
            }

            $value = (string) $value;
            if (in_array($value, $secretValues, true)) {
                throw new UserException(sprintf(
                    'Component secrets cannot be used in configurations (used in "%s"). ' .
                    'Please contact support if you need further explanation.',
                    implode('.', [...$path, $key]),
                ));
            }
        }
    }
}
