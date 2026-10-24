<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/modules/saas/api_runtime.php';

[$controlConn, $method] = saas_api_bootstrap();
saas_api_handle_request($controlConn, $method);
