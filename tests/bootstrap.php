<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Force test environment (PHPUnit sets this via server variable)
$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'];

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
