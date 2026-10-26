<?php

use App\Services\GameSessions;

test('the lane time for games is games times people times the minutes per game, rounded up to a step', function (int $games, int $partySize, int $minutes) {
    expect(app(GameSessions::class)->minutesFor($games, $partySize))->toBe($minutes);
})->with([
    'four people, three games' => [3, 4, 120],
    'one person, one game, rounded up to the half hour' => [1, 1, 30],
    'a full lane, ten games' => [10, 6, 600],
    // 14 people need three lanes, bowling side by side, five on each.
    'a big group spread over lanes' => [2, 14, 120],
]);

test('the minutes per person per game come from the rules', function () {
    config(['bowling.minutes_per_player_game' => 12]);

    expect(app(GameSessions::class)->minutesFor(2, 5))->toBe(120);
});

test('what is offered follows the venue\'s choice', function (string $mode, bool $time, bool $games) {
    config(['bowling.session_mode' => $mode]);

    expect([app(GameSessions::class)->offersTime(), app(GameSessions::class)->offersGames()])->toBe([$time, $games]);
})->with([
    'time only' => ['time', true, false],
    'games only' => ['games', false, true],
    'both' => ['both', true, true],
]);

test('the longest lane time any games can need is the most games by a full lane', function () {
    config(['bowling.session_mode' => 'both']);

    expect(app(GameSessions::class)->longestMinutes())->toBe(600);
});
