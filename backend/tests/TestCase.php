<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->guardAgainstNonTestDatabase();
    }

    /**
     * Hard fail-safe: the test suite must never touch the production MySQL
     * database. This runs after the application boots but before any
     * migration trait (RefreshDatabase / DatabaseMigrations) executes.
     */
    protected function guardAgainstNonTestDatabase(): void
    {
        $default = (string) config('database.default');
        $connection = (array) config("database.connections.$default", []);

        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Unsafe test database configuration: expected in-memory SQLite, got connection [%s] with driver [%s] and database [%s]. Tests aborted to protect production data.',
                $default,
                $driver ?? 'null',
                is_string($database) ? $database : gettype($database)
            ));
        }
    }
}
