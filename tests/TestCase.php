<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety guard: refuse to run any test unless the default database
     * connection points at a scratch database whose name ends in "_test".
     *
     * This runs inside createApplication(), i.e. BEFORE any test trait
     * (RefreshDatabase's migrate:fresh included) touches the database, so
     * a misconfigured run aborts here instead of wiping furniture_db.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database   = (string) $app['config']->get("database.connections.{$connection}.database");

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests: the database name must end in '_test' "
                . "(the current connection '{$connection}' points at a database that does not). "
                . 'Tests use furniture_db_test — see phpunit.xml. No database was touched.'
            );
        }

        return $app;
    }
}
