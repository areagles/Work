<?php
// Compatibility endpoint:
// Allows APP_LICENSE_REMOTE_URL to use /api/license/check
// while keeping the real handler in /license_api.php.
require_once __DIR__ . '/../../../license_api.php';
