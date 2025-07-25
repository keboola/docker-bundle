<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\DataTypeSupport;

class Environment
{
    private readonly ?string $configId;
    private readonly ?string $configVersion;
    private readonly array $tokenInfo;
    private readonly ?string $runId;
    private readonly string $url;
    private readonly ComponentSpecification $component;
    private readonly string $stackId;
    private readonly ?string $configRowId;
    private readonly string $token;
    private readonly ?string $branchId;
    private readonly string $mode;
    private readonly DataTypeSupport $dataTypeSupport;

    public function __construct(
        ?string $configId,
        ?string $configVersion,
        ?string $configRowId,
        ComponentSpecification $component,
        array $config,
        array $tokenInfo,
        ?string $runId,
        string $url,
        string $token,
        ?string $branchId,
        string $mode,
        DataTypeSupport $dataTypeSupport,
    ) {
        $this->configId = $configId ?: sha1(serialize($config));
        $this->configVersion = $configVersion;
        $this->component = $component;
        $this->tokenInfo = $tokenInfo;
        $this->runId = $runId;
        $this->url = $url;
        $this->stackId = (string) parse_url($url, PHP_URL_HOST);
        $this->token = $token;
        $this->configRowId = $configRowId;
        $this->branchId = $branchId;
        $this->mode = $mode;
        $this->dataTypeSupport = $dataTypeSupport;
    }

    public function getEnvironmentVariables(OutputFilterInterface $outputFilter): array
    {
        // set environment variables
        $envs = [
            'KBC_RUNID' => $this->runId,
            'KBC_PROJECTID' => $this->tokenInfo['owner']['id'],
            'KBC_DATADIR' => '/data/',
            'KBC_CONFIGID' => $this->configId,
            'KBC_CONFIGVERSION' => (string) $this->configVersion,
            'KBC_COMPONENTID' => $this->component->getId(),
            'KBC_STACKID' => $this->stackId,
            'KBC_STAGING_FILE_PROVIDER' => $this->tokenInfo['owner']['fileStorageProvider'],
            'KBC_PROJECT_FEATURE_GATES' => implode(',', $this->tokenInfo['owner']['features']),
            'KBC_COMPONENT_RUN_MODE' => $this->mode,
        ];

        if ($this->hasNativeTypesFeature()) {
            $envs['KBC_DATA_TYPE_SUPPORT'] = $this->dataTypeSupport->value;
        }

        if ($this->configRowId) {
            $envs['KBC_CONFIGROWID'] = $this->configRowId;
        }
        if ($this->component->hasForwardToken()) {
            $envs['KBC_TOKEN'] = $this->token;
            $outputFilter->addValue($this->token);
            $envs['KBC_URL'] = $this->url;
        }
        if ($this->component->hasForwardTokenDetails()) {
            $envs['KBC_PROJECTNAME'] = $this->tokenInfo['owner']['name'];
            $envs['KBC_TOKENID'] = $this->tokenInfo['id'];
            $envs['KBC_TOKENDESC'] = $this->tokenInfo['description'];
            if (!empty($this->tokenInfo['admin']['samlParameters']['userId'])) {
                $envs['KBC_REALUSER'] = $this->tokenInfo['admin']['samlParameters']['userId'];
            }
        }
        if ($this->branchId) {
            $envs['KBC_BRANCHID'] = (string) $this->branchId;
        }

        return $envs;
    }

    private function hasNativeTypesFeature(): bool
    {
        return in_array('new-native-types', $this->tokenInfo['owner']['features']);
    }
}
