<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';

Auth::init();
Auth::requireRole('admin');

flash('info', 'Facebook now uses a System User token. Enter your Page ID and token directly in Settings.');
redirect(siteUrl('admin/settings'));
