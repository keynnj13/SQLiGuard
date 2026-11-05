<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run tests unless the default connection is in-memory SQLite.
     *
     * phpunit.xml sets this, but a cached configuration (`php artisan
     * config:cache`) would override it and point RefreshDatabase at the real
     * sqliguardDB, wiping application data. Fail before any migration runs.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests must use in-memory SQLite, but the default connection is [{$default}]. "
                .'Run `php artisan config:clear` and check phpunit.xml before running tests.'
            );
        }

        return $app;
    }
}
