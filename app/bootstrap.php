<?php

declare(strict_types=1);

// Bootstrap aplikasi: inisialisasi path, helper, dan koneksi database.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', PROJECT_ROOT . '/public');
}

if (!defined('DATABASE_PATH')) {
    define('DATABASE_PATH', PROJECT_ROOT . '/database');
}

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', PROJECT_ROOT . '/storage');
}

if (!defined('LAYOUT_PATH')) {
    define('LAYOUT_PATH', APP_ROOT . '/layouts');
}

if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'production');
}

require_once APP_ROOT . '/helpers.php';
require_once APP_ROOT . '/database.php';
