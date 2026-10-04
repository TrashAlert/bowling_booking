<?php

use App\Models\OpeningHour;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The form as an admin sends it: open 10:00 to 23:00 every day, with
 * changes for the days given (0 is Sunday).
 *
 * @param  array<int, array<string, mixed>>  $changes
 * @return array{days: list<array<string, mixed>>}
 */
function hoursForm(array $changes = []): array
{
    return ['days' => array_map(
        fn (int $weekday) => ['weekday' => $weekday, 'open' => true, 'opens' => '10:00', 'closes' => '23:00', ...($changes[$weekday] ?? [])],
        [1, 2, 3, 4, 5, 6, 0],
    )];
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('opening-hours.edit'));

    $response->assertRedirect(route('login'));
});

test('staff who are not admins cannot open or change the opening hours', function (string $method, string $route) {
    $response = $this->actingAs(User::factory()->staff()->create())->{$method}(route($route), hoursForm());

    $response->assertForbidden();
    $this->assertDatabaseCount('opening_hours', 0);
})->with([
    'open the page' => ['get', 'opening-hours.edit'],
    'change the hours' => ['put', 'opening-hours.update'],
]);

test('admins see the week, not yet set, and the venue\'s time zone', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('opening-hours.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/opening-hours')
        ->where('isSet', false)
        ->where('timezone', 'Asia/Kuala_Lumpur')
        ->has('week', 7)
        ->where('week.0', ['weekday' => 1, 'opens' => null, 'closes' => null]));
});

test('admins can set the hours for every day, including a closed day and a late night', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->put(route('opening-hours.update'), hoursForm([
        5 => ['closes' => '01:00'],
        0 => ['open' => false, 'opens' => null, 'closes' => null],
    ]));

    $response->assertRedirect(route('opening-hours.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Opening hours saved.']);
    expect(OpeningHour::query()->orderBy('weekday')->get(['weekday', 'opens_at', 'closes_at'])->toArray())->toBe([
        ['weekday' => 0, 'opens_at' => null, 'closes_at' => null],
        ['weekday' => 1, 'opens_at' => '10:00:00', 'closes_at' => '23:00:00'],
        ['weekday' => 2, 'opens_at' => '10:00:00', 'closes_at' => '23:00:00'],
        ['weekday' => 3, 'opens_at' => '10:00:00', 'closes_at' => '23:00:00'],
        ['weekday' => 4, 'opens_at' => '10:00:00', 'closes_at' => '23:00:00'],
        ['weekday' => 5, 'opens_at' => '10:00:00', 'closes_at' => '01:00:00'],
        ['weekday' => 6, 'opens_at' => '10:00:00', 'closes_at' => '23:00:00'],
    ]);
});

test('an open day needs sensible times', function (array $change, string $field, string $message) {
    $response = $this->actingAs(User::factory()->admin()->create())->put(route('opening-hours.update'), hoursForm([1 => $change]));

    $response->assertSessionHasErrors([$field => $message]);
    $this->assertDatabaseCount('opening_hours', 0);
})->with([
    'no opening time' => [['opens' => null], 'days.0.opens', 'Give an opening time, or mark the day closed.'],
    'no closing time' => [['closes' => null], 'days.0.closes', 'Give a closing time, or mark the day closed.'],
    'not a time' => [['opens' => '10am'], 'days.0.opens', 'Give the opening time as hours and minutes.'],
    'closing when it opens' => [['closes' => '10:00'], 'days.0.closes', 'The closing time can\'t be the same as the opening time.'],
]);

test('every day of the week must be given, once each', function () {
    $form = hoursForm();
    $form['days'][6]['weekday'] = 1;

    $response = $this->actingAs(User::factory()->admin()->create())->put(route('opening-hours.update'), $form);

    $response->assertSessionHasErrors('days.6.weekday');
    $this->assertDatabaseCount('opening_hours', 0);
});
