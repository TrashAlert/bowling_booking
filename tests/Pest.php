<?php

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Lane;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\WaitlistDeposits;
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
function bookReservation(CarbonImmutable $startsAt, int $minutes = 60, int $partySize = 4): Booking
{
    return app(BookingService::class)->book(
        Customer::factory()->create(),
        $minutes,
        $partySize,
        $startsAt,
        BookingSource::Phone,
    );
}

/**
 * Reserve the given lanes the way staff do: picked by hand, each closed for
 * the hour before the start.
 *
 * @param  Lane|array<int, Lane>  $lanes
 */
function reserveLanes(Lane|array $lanes, CarbonImmutable $startsAt, int $minutes = 60, int $partySize = 4, string $name = 'Reserved party'): Booking
{
    return app(BookingService::class)->reserveForNewCustomer(
        $name,
        '0123456789',
        collect(is_array($lanes) ? $lanes : [$lanes]),
        $minutes,
        $partySize,
        $startsAt,
    );
}

/**
 * Put a new walk-in party at the end of the waitlist.
 */
function joinWaitlist(int $minutes = 60, int $partySize = 4, string $name = 'Walk-in party'): WaitlistEntry
{
    return app(WaitlistService::class)->joinAsNewCustomer($name, null, $minutes, $partySize);
}

/**
 * Put a party in line the way one that joins online gets there: it asks to
 * join, then pays its deposit.
 */
function joinWaitlistOnline(int $minutes = 60, int $partySize = 4, string $name = 'Online party'): WaitlistEntry
{
    $deposits = app(WaitlistDeposits::class);

    return $deposits->confirmPayment($deposits->start($name, '0123456789', $minutes, $partySize));
}
