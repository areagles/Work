<?php
// Compatibility endpoint:
// Allows APP_CLOUD_SYNC_REMOTE_URL to use /api/cloud/sync
// while keeping the real handler in /cloud_sync_api.php.
require_once __DIR__ . '/../../../cloud_sync_api.php';

