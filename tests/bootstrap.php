<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('APP_RUNNING_TESTS', true);

putenv('APP_LICENSE_OWNER_LAB_UNLOCK=0');
$_ENV['APP_LICENSE_OWNER_LAB_UNLOCK'] = '0';
$_SERVER['APP_LICENSE_OWNER_LAB_UNLOCK'] = '0';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../inventory_engine.php';
require_once __DIR__ . '/../finance_engine.php';
require_once __DIR__ . '/Support/TestConfig.php';
require_once __DIR__ . '/Support/TestDatabase.php';
require_once __DIR__ . '/Support/DatabaseTestCase.php';
