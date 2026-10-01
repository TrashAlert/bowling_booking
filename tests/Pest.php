<?php

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Package;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Book a confirmed phone reservation through the real booking service.
 */
function bookReservation(Package $package, CarbonImmutable $startsAt, int $partySize = 4): Booking
{
    return app(BookingService::class)->book(
        Customer::factory()->create(),
        $package,
        $partySize,
        $startsAt,
        BookingSource::Phone,
    );
}

/**
 * Put a new walk-in party at the end of the waitlist.
 */
function joinWaitlist(Package $package, int $partySize = 4, string $name = 'Walk-in party'): WaitlistEntry
{
    return app(WaitlistService::class)->joinAsNewCustomer($name, null, $package, $partySize);
}
