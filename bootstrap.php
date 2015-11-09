<?php
// Define path to application directory
define('ROOT_PATH', __DIR__);

// Ensure library/ is on include_path
/*
set_include_path(implode(PATH_SEPARATOR, array(
	realpath(ROOT_PATH . '/library'),
	get_include_path(),
)));
*/
ini_set('display_errors', true);

date_default_timezone_set('Europe/Prague');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

require_once ROOT_PATH . '/vendor/autoload.php';

defined('STORAGE_API_TOKEN')
    || define('STORAGE_API_TOKEN', getenv('STORAGE_API_TOKEN') ? getenv('STORAGE_API_TOKEN') : 'your_token');


defined('DOCKERHUB_PRIVATE_USERNAME')
    || define('DOCKERHUB_PRIVATE_USERNAME', getenv('DOCKERHUB_PRIVATE_USERNAME') ? getenv('DOCKERHUB_PRIVATE_USERNAME') : 'username');

defined('DOCKERHUB_PRIVATE_EMAIL')
    || define('DOCKERHUB_PRIVATE_EMAIL', getenv('DOCKERHUB_PRIVATE_EMAIL') ? getenv('DOCKERHUB_PRIVATE_EMAIL') : 'email');

defined('DOCKERHUB_PRIVATE_PASSWORD')
    || define('DOCKERHUB_PRIVATE_PASSWORD', getenv('DOCKERHUB_PRIVATE_PASSWORD') ? getenv('DOCKERHUB_PRIVATE_PASSWORD') : 'password');

defined('DOCKERHUB_PRIVATE_SERVER')
    || define('DOCKERHUB_PRIVATE_SERVER', getenv('DOCKERHUB_PRIVATE_SERVER') ? getenv('DOCKERHUB_PRIVATE_SERVER') : 'server');

defined('GIT_PRIVATE_USERNAME')
    || define('GIT_PRIVATE_USERNAME', getenv('GIT_PRIVATE_USERNAME') ? getenv('GIT_PRIVATE_USERNAME') : 'username');

defined('GIT_PRIVATE_PASSWORD')
    || define('GIT_PRIVATE_PASSWORD', getenv('GIT_PRIVATE_PASSWORD') ? getenv('GIT_PRIVATE_PASSWORD') : 'password');
