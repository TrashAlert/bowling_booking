<?php

use App\Services\OpeningHours;
use Carbon\CarbonImmutable;

// In the venue's time zone (Kuala Lumpur), 5 October 2026 is a Monday.

test('the venue counts as always open until hours are set', function () {
    $this->travelTo(venueTime('2026-10-05 03:00'));

    expect(app(OpeningHours::class)->isOpenAt(now()))->toBeTrue()
        ->and(app(OpeningHours::class)->status())->toBe(['isOpen' => true, 'opensAt' => null, 'closesAt' => null]);
});

test('the venue is open within the day\'s hours and closed outside them', function (string $moment, bool $open) {
    openDaily('10:00', '23:00');

    expect(app(OpeningHours::class)->isOpenAt(venueTime($moment)))->toBe($open);
})->with([
    'a minute before opening' => ['2026-10-05 09:59', false],
    'at opening time' => ['2026-10-05 10:00', true],
    'a minute before closing' => ['2026-10-05 22:59', true],
    'at closing time' => ['2026-10-05 23:00', false],
]);

test('hours are read in the venue\'s time zone, whatever the server\'s clock says', function () {
    openDaily('10:00', '23:00');

    // 02:00 in UTC is 10:00 in Kuala Lumpur.
    expect(app(OpeningHours::class)->isOpenAt(CarbonImmutable::parse('2026-10-05 02:00', 'UTC')))->toBeTrue()
        ->and(app(OpeningHours::class)->isOpenAt(CarbonImmutable::parse('2026-10-05 01:59', 'UTC')))->toBeFalse();
});

test('a night that runs past midnight stays open into the next morning', function (string $moment, bool $open) {
    // Friday runs until 1am; Saturday opens at noon.
    openDaily('10:00', '23:00', except: [5 => ['opens' => '10:00', 'closes' => '01:00'], 6 => ['opens' => '12:00', 'closes' => '23:00']]);

    expect(app(OpeningHours::class)->isOpenAt(venueTime($moment)))->toBe($open);
})->with([
    'late on Friday' => ['2026-10-09 23:30', true],
    'just after midnight' => ['2026-10-10 00:30', true],
    'at 1am' => ['2026-10-10 01:00', false],
    'Saturday morning' => ['2026-10-10 11:00', false],
]);

test('a session must fall wholly inside one opening', function (string $start, string $end, bool $covered) {
    // Friday runs until 1am.
    openDaily('10:00', '23:00', except: [5 => ['opens' => '10:00', 'closes' => '01:00']]);

    expect(app(OpeningHours::class)->coversSession(venueTime($start), venueTime($end)))->toBe($covered);
})->with([
    'inside the day' => ['2026-10-05 19:00', '2026-10-05 20:30', true],
    'ending exactly at closing' => ['2026-10-05 22:00', '2026-10-05 23:00', true],
    'running past closing' => ['2026-10-05 22:30', '2026-10-05 23:30', false],
    'starting before opening' => ['2026-10-05 09:30', '2026-10-05 10:30', false],
    'into a late night past midnight' => ['2026-10-09 23:30', '2026-10-10 00:30', true],
    'running past a late close' => ['2026-10-10 00:30', '2026-10-10 01:30', false],
]);

test('any session is fine while no hours are set', function () {
    expect(app(OpeningHours::class)->coversSession(venueTime('2026-10-05 03:00'), venueTime('2026-10-05 04:00')))->toBeTrue();
});

test('a day marked closed is closed all day', function () {
    openDaily('10:00', '23:00', except: [0 => null]);

    expect(app(OpeningHours::class)->isOpenAt(venueTime('2026-10-04 15:00')))->toBeFalse();
});

test('while closed it says when the venue opens again', function (string $moment, string $notice, string $opensAt) {
    openDaily('10:00', '23:00', except: [0 => null]);
    $hours = app(OpeningHours::class);

    expect($hours->closedNotice(venueTime($moment)))->toBe($notice)
        ->and($hours->opensNext(venueTime($moment))->utc()->toIso8601String())->toBe($opensAt);
})->with([
    'early in the morning' => ['2026-10-05 08:00', 'We are closed right now. We open again today at 10:00 AM.', '2026-10-05T02:00:00+00:00'],
    'after closing' => ['2026-10-05 23:30', 'We are closed right now. We open again tomorrow at 10:00 AM.', '2026-10-06T02:00:00+00:00'],
    'before a closed Sunday' => ['2026-10-03 23:30', 'We are closed right now. We open again on Monday at 10:00 AM.', '2026-10-05T02:00:00+00:00'],
]);

test('while open it says when the venue closes', function () {
    openDaily('10:00', '23:00', except: [5 => ['opens' => '10:00', 'closes' => '01:00']]);
    $this->travelTo(venueTime('2026-10-09 22:00'));

    expect(app(OpeningHours::class)->status())->toBe([
        'isOpen' => true,
        'opensAt' => null,
        'closesAt' => '2026-10-09T17:00:00+00:00',
    ]);
});

test('a venue closed every day never opens', function () {
    openDaily(except: array_fill(0, 7, null));

    expect(app(OpeningHours::class)->opensNext(venueTime('2026-10-05 12:00')))->toBeNull()
        ->and(app(OpeningHours::class)->closedNotice(venueTime('2026-10-05 12:00')))->toBe('We are closed right now.');
});

test('the week is listed Monday first, with times as hours and minutes', function () {
    openDaily('10:00', '23:00', except: [0 => null]);

    $week = app(OpeningHours::class)->week();

    expect(array_column($week, 'weekday'))->toBe([1, 2, 3, 4, 5, 6, 0])
        ->and($week[0])->toBe(['weekday' => 1, 'opens' => '10:00', 'closes' => '23:00'])
        ->and($week[6])->toBe(['weekday' => 0, 'opens' => null, 'closes' => null]);
});
