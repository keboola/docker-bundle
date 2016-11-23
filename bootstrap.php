<?php
// Define path to application directory
define('ROOT_PATH', __DIR__);
ini_set('display_errors', true);

date_default_timezone_set('Europe/Prague');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

require_once ROOT_PATH . '/vendor/autoload.php';

defined('STORAGE_API_URL')
|| define('STORAGE_API_URL', getenv('STORAGE_API_URL') ?: 'sapi_url');

defined('STORAGE_API_TOKEN')
    || define('STORAGE_API_TOKEN', getenv('STORAGE_API_TOKEN') ?: 'your_token');

defined('DOCKERHUB_PRIVATE_USERNAME')
    || define('DOCKERHUB_PRIVATE_USERNAME', getenv('DOCKERHUB_PRIVATE_USERNAME') ?: 'username');

defined('DOCKERHUB_PRIVATE_PASSWORD')
    || define('DOCKERHUB_PRIVATE_PASSWORD', getenv('DOCKERHUB_PRIVATE_PASSWORD') ?: 'password');

defined('DOCKERHUB_PRIVATE_SERVER')
    || define('DOCKERHUB_PRIVATE_SERVER', getenv('DOCKERHUB_PRIVATE_SERVER') ?: 'server');

defined('GIT_PRIVATE_USERNAME')
    || define('GIT_PRIVATE_USERNAME', getenv('GIT_PRIVATE_USERNAME') ?: 'username');

defined('GIT_PRIVATE_PASSWORD')
    || define('GIT_PRIVATE_PASSWORD', getenv('GIT_PRIVATE_PASSWORD') ?: 'password');

defined('QUAYIO_PRIVATE_USERNAME')
    || define('QUAYIO_PRIVATE_USERNAME', getenv('QUAYIO_PRIVATE_USERNAME') ?: 'username');

defined('QUAYIO_PRIVATE_PASSWORD')
    || define('QUAYIO_PRIVATE_PASSWORD', getenv('QUAYIO_PRIVATE_PASSWORD') ?: 'password');

defined('AWS_ECR_REGISTRY_URI')
|| define('AWS_ECR_REGISTRY_URI', getenv('AWS_ECR_REGISTRY_URI') ?: 'foobar.amazon.com');

defined('AWS_ECR_REGISTRY_REGION')
|| define('AWS_ECR_REGISTRY_REGION', getenv('AWS_ECR_REGISTRY_REGION') ?: 'up-north-01');

defined('AWS_ECR_ACCESS_KEY_ID')
|| define('AWS_ECR_ACCESS_KEY_ID', getenv('AWS_ECR_ACCESS_KEY_ID') ?: 'key');

defined('AWS_ECR_SECRET_ACCESS_KEY')
|| define('AWS_ECR_SECRET_ACCESS_KEY', getenv('AWS_ECR_SECRET_ACCESS_KEY') ?: 'secret');
