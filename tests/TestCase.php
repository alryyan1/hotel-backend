<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run (and let RefreshDatabase wipe) anything but the dedicated test database.
     * A cached config (bootstrap/cache/config.php) silently overrides phpunit.xml's DB settings.
     */
    protected function setUpTraits()
    {
        $database = config('database.connections.' . config('database.default') . '.database');

        if ($database !== 'hotel_testing') {
            throw new RuntimeException("Tests must run against 'hotel_testing', got '{$database}'. Run `php artisan config:clear`.");
        }

        return parent::setUpTraits();
    }
}
