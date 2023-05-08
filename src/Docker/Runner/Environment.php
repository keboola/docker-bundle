<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;

class Environment
{
    readonly private ?string $configId;
    readonly private array $tokenInfo;
    readonly private ?string $runId;
    readonly private string $url;
    readonly private Component $component;
    readonly private string $stackId;
    readonly private ?string $configRowId;
    readonly private string $token;
    readonly private ?string $branchId;
    readonly private ?string $absConnectionString;
    readonly private ?MlflowTracking $mlflowTracking;
    readonly private string $mode;

    public function __construct(
        ?string $configId,
        ?string $configRowId,
        Component $component,
        array $config,
        array $tokenInfo,
        ?string $runId,
        string $url,
        string $token,
        ?string $branchId,
        ?string $absConnectionString,
        ?MlflowTracking $mlflowTracking,
        string $mode,
    ) {
        $this->configId = $configId ?: sha1(serialize($config));
        $this->component = $component;
        $this->tokenInfo = $tokenInfo;
        $this->runId = $runId;
        $this->url = $url;
        $this->stackId = (string) parse_url($url, PHP_URL_HOST);
        $this->token = $token;
        $this->configRowId = $configRowId;
        $this->branchId = $branchId;
        $this->absConnectionString = $absConnectionString;
        $this->mlflowTracking = $mlflowTracking;
        $this->mode = $mode;
    }

    public function getEnvironmentVariables(OutputFilterInterface $outputFilter): array
    {
        // set environment variables
        $envs = [
            'KBC_RUNID' => $this->runId,
            'KBC_PROJECTID' => $this->tokenInfo['owner']['id'],
            'KBC_DATADIR' => '/data/',
            'KBC_CONFIGID' => $this->configId,
            'KBC_COMPONENTID' => $this->component->getId(),
            'KBC_STACKID' => $this->stackId,
            'KBC_STAGING_FILE_PROVIDER' => $this->tokenInfo['owner']['fileStorageProvider'],
            'KBC_PROJECT_FEATURE_GATES' => implode(',', $this->tokenInfo['owner']['features']),
            'KBC_COMPONENT_RUN_MODE' => $this->mode,
        ];
        if ($this->configRowId) {
            $envs['KBC_CONFIGROWID'] = $this->configRowId;
        }
        if ($this->component->forwardToken()) {
            $envs['KBC_TOKEN'] = $this->token;
            $outputFilter->addValue($this->token);
            $envs['KBC_URL'] = $this->url;
        }
        if ($this->component->forwardTokenDetails()) {
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

        if ($this->absConnectionString !== null) {
            $envs['AZURE_STORAGE_CONNECTION_STRING'] = $this->absConnectionString;
        }

        if ($this->mlflowTracking !== null) {
            $envs = array_merge($envs, $this->mlflowTracking->exportAsEnv($outputFilter));
        }

        return $envs;
    }
}
