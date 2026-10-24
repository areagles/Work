<?php
// Hostinger cron bridge.
// This file is intended to be deployed to a path already referenced by the hosting cron job.

$_GET['token'] = 'd1f0e15d5c893df0ef5a1d9f32b5447299f00424c1f2260ee6e29916a497e2a0';
$_GET['action'] = 'run';
$_GET['recalculate'] = '1';

require '/home/u159629331/domains/areagles.com/public_html/work/saas_automation.php';
