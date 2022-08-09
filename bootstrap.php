<?php
// Define path to application directory
define('ROOT_PATH', __DIR__);
ini_set('display_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Prague');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

require_once ROOT_PATH . '/vendor/autoload.php';

defined('STORAGE_API_URL')
|| define('STORAGE_API_URL', getenv('STORAGE_API_URL') ?: 'sapi_url');

defined('STORAGE_API_TOKEN')
|| define('STORAGE_API_TOKEN', getenv('STORAGE_API_TOKEN') ?: 'your_token');

defined('STORAGE_API_TOKEN_MASTER')
|| define('STORAGE_API_TOKEN_MASTER', getenv('STORAGE_API_TOKEN_MASTER') ?: 'your_token');

defined('STORAGE_API_TOKEN_READ_ONLY')
|| define('STORAGE_API_TOKEN_READ_ONLY', getenv('STORAGE_API_TOKEN_READ_ONLY') ?: 'read_only_your_token');

defined('STORAGE_API_TOKEN_FIXED_TYPE')
|| define('STORAGE_API_TOKEN_FIXED_TYPE', getenv('STORAGE_API_TOKEN_FIXED_TYPE') ?: 'fixed_type_your_token');

defined('STORAGE_API_URL_SYNAPSE')
|| define('STORAGE_API_URL_SYNAPSE', getenv('STORAGE_API_URL_SYNAPSE') ?: 'sapi_url');

defined('STORAGE_API_TOKEN_SYNAPSE')
|| define('STORAGE_API_TOKEN_SYNAPSE', getenv('STORAGE_API_TOKEN_SYNAPSE') ?: 'your_token');

defined('RUN_SYNAPSE_TESTS')
|| define('RUN_SYNAPSE_TESTS', getenv('RUN_SYNAPSE_TESTS') ?: '0');

defined('STORAGE_API_URL_EXASOL')
|| define('STORAGE_API_URL_EXASOL', getenv('STORAGE_API_URL_EXASOL') ?: 'sapi_url');

defined('STORAGE_API_TOKEN_EXASOL')
|| define('STORAGE_API_TOKEN_EXASOL', getenv('STORAGE_API_TOKEN_EXASOL') ?: 'your_token');

defined('RUN_EXASOL_TESTS')
|| define('RUN_EXASOL_TESTS', getenv('RUN_EXASOL_TESTS') ?: '0');

defined('STORAGE_API_URL_TERADATA')
|| define('STORAGE_API_URL_TERADATA', getenv('STORAGE_API_URL_TERADATA') ?: 'sapi_url');

defined('STORAGE_API_TOKEN_TERADATA')
|| define('STORAGE_API_TOKEN_TERADATA', getenv('STORAGE_API_TOKEN_TERADATA') ?: 'your_token');

defined('RUN_TERADATA_TESTS')
|| define('RUN_TERADATA_TESTS', getenv('RUN_TERADATA_TESTS') ?: '0');

defined('GIT_PRIVATE_USERNAME')
    || define('GIT_PRIVATE_USERNAME', getenv('GIT_PRIVATE_USERNAME') ?: 'username');

defined('GIT_PRIVATE_PASSWORD')
    || define('GIT_PRIVATE_PASSWORD', getenv('GIT_PRIVATE_PASSWORD') ?: 'password');

defined('AWS_ECR_REGISTRY_URI')
|| define('AWS_ECR_REGISTRY_URI', getenv('AWS_ECR_REGISTRY_URI') ?: 'foobar.amazon.com');

defined('AWS_ECR_REGISTRY_REGION')
|| define('AWS_ECR_REGISTRY_REGION', getenv('AWS_ECR_REGISTRY_REGION') ?: 'up-north-01');

defined('AWS_ECR_REGISTRY_ACCOUNT_ID')
|| define('AWS_ECR_REGISTRY_ACCOUNT_ID', getenv('AWS_ECR_REGISTRY_ACCOUNT_ID') ?: '123456');

defined('AWS_ECR_ACCESS_KEY_ID')
|| define('AWS_ECR_ACCESS_KEY_ID', getenv('AWS_ECR_ACCESS_KEY_ID') ?: 'key');

defined('AWS_ECR_SECRET_ACCESS_KEY')
|| define('AWS_ECR_SECRET_ACCESS_KEY', getenv('AWS_ECR_SECRET_ACCESS_KEY') ?: 'secret');

defined('AWS_KMS_TEST_KEY')
|| define('AWS_KMS_TEST_KEY', getenv('AWS_KMS_TEST_KEY') ?: 'alias/something');

defined('RUNNER_COMMAND_TO_GET_HOST_IP')
|| define(
    'RUNNER_COMMAND_TO_GET_HOST_IP',
    getenv('RUNNER_COMMAND_TO_GET_HOST_IP') ?: 'ip -4 addr show eth0 | grep -Po \'inet \K[\d.]+\''
);

defined('RUNNER_MIN_LOG_PORT')
|| define('RUNNER_MIN_LOG_PORT', getenv('RUNNER_MIN_LOG_PORT') ?: 12202);

defined('RUNNER_MAX_LOG_PORT')
|| define('RUNNER_MAX_LOG_PORT', getenv('RUNNER_MAX_LOG_PORT') ?: 13202);
