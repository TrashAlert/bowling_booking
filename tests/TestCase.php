<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database the tests may run on. It is set in phpunit.xml.
     */
    private const TEST_DATABASE = 'bowling_test';

    /**
     * Refuse to run on anything but the test database.
     *
     * The suite empties the database it runs on. A DB_DATABASE set in the
     * shell wins over phpunit.xml, and would send the tests to the real
     * database and wipe it, which has happened once. This stops the run
     * before anything is touched.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException(
                'Tests must run on the ['.self::TEST_DATABASE."] database, but this run is pointed at [{$database}]. "
                .'Unset DB_DATABASE in your shell and run the tests again.'
            );
        }

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
