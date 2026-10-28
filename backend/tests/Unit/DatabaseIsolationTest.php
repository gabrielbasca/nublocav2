<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_test_suite_is_isolated_from_production_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(
            app()->configurationIsCached(),
            'Tests must not load the production cached config (it contains the MySQL connection).'
        );
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }
}
