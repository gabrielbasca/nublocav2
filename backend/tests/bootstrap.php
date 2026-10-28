<?php

/*
 |--------------------------------------------------------------------------
 | Test Bootstrap — Production Database Isolation
 |--------------------------------------------------------------------------
 |
 | PHPUnit's <env force="true"> only writes putenv() and $_ENV, but
 | Laravel's environment repository reads $_SERVER first — so MySQL
 | variables exported in the shell could otherwise leak into the test
 | process (this happened once and wrote test fixtures to production).
 | Sanitize every environment source here, before any application boots.
 | Tests\TestCase additionally fails closed if the resolved connection
 | is not in-memory SQLite.
 |
 */

require __DIR__.'/../vendor/autoload.php';

$testEnvironment = [
    'APP_ENV' => 'testing',
    // Point at a file that does not exist so tests never load the
    // production cached config (bootstrap/cache/config.php), which
    // contains the MySQL connection.
    'APP_CONFIG_CACHE' => 'bootstrap/cache/config-test.php',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
];

foreach ($testEnvironment as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}
