<?php

declare(strict_types=1);

use Keboola\StorageApi\Exception;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/.env.local')) {
    (new Dotenv())->usePutenv(true)->bootEnv(dirname(__DIR__).'/.env.local', 'dev', [], true);
}

$requiredEnvs = ['STORAGE_API_URL', 'STORAGE_API_TOKEN', 'STORAGE_API_TOKEN_MASTER', 'STORAGE_API_TOKEN_READ_ONLY',
    'AWS_ECR_REGISTRY_URI', 'AWS_ECR_REGISTRY_REGION', 'AWS_ECR_REGISTRY_REGION', 'AWS_ECR_REGISTRY_ACCOUNT_ID',
    'AWS_ECR_ACCESS_KEY_ID', 'AWS_ECR_SECRET_ACCESS_KEY', 'AWS_KMS_TEST_KEY', 'RUNNER_COMMAND_TO_GET_HOST_IP',
    'RUNNER_MIN_LOG_PORT', 'RUNNER_MAX_LOG_PORT',
];

foreach ($requiredEnvs as $env) {
    if (empty(getenv($env))) {
        throw new Exception(sprintf('Environment variable "%s" is empty', $env));
    }
}
