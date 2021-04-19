<?php

namespace Keboola\DockerBundle\Job;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\CreditsChecker;
use Keboola\DockerBundle\Docker\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFile;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Docker\SharedCodeResolver;
use Keboola\DockerBundle\Docker\VariableResolver;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Temp\Temp;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;
use Symfony\Component\Process\Process;

class TagResolverHelper
{
    public static function resolveComponentImageTag(
        array $requestParameters,
        array $componentConfiguration,
        Component $component
    ) {
        if (isset($requestParameters['tag']) &&
            $requestParameters['tag'] !== ''
        ) {
            return $requestParameters['tag'];
        }
        
        if (isset($componentConfiguration['runtime']['image_tag']) &&
            $componentConfiguration['runtime']['image_tag'] !== ''
        ) {
            return $componentConfiguration['runtime']['image_tag'];
        }
        
        return $component->getImageTag();
    }
        array $requestParameters,
        array $componentConfiguration,
        Component $component
    ) {
        if (isset($requestParameters['tag']) &&
            $requestParameters['tag'] !== ''
        ) {
            $tag = $requestParameters['tag'];
        } elseif (isset($componentConfiguration['runtime']['image_tag']) &&
            $componentConfiguration['runtime']['image_tag'] !== ''
        ) {
            $tag = $componentConfiguration['runtime']['image_tag'];
        } else {
            $tag = $component->getImageTag();
        }
        return $tag;
    }
}
