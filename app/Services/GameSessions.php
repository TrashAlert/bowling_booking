<?php

namespace App\Services;

/**
 * Sessions sold by the game instead of by time, for a venue that offers
 * them (bowling.session_mode).
 *
 * The rest of the app keeps a lane for a length of time, so a group that
 * books games is given an estimate: its games times its people times how
 * long one person takes to bowl a game, rounded up to a session step. The
 * number of games is kept alongside, for staff and the group to see. Staff
 * end the session sooner when the games are done, or extend it.
 */
class GameSessions
{
    public const TIME = 'time';

    public const GAMES = 'games';

    public const BOTH = 'both';

    /**
     * What a venue can offer.
     *
     * @var list<string>
     */
    public const MODES = [self::TIME, self::GAMES, self::BOTH];

    /**
     * Whether groups can book a length of time.
     */
    public function offersTime(): bool
    {
        return config('bowling.session_mode') !== self::GAMES;
    }

    /**
     * Whether groups can book a number of games.
     */
    public function offersGames(): bool
    {
        return in_array(config('bowling.session_mode'), [self::GAMES, self::BOTH], true);
    }

    /**
     * How long to keep the lanes for $games games by a party of $partySize,
     * in minutes. A party bigger than one lane holds is spread over the
     * lanes it needs, and the lanes bowl side by side, so the time is
     * worked out for the people on one lane.
     */
    public function minutesFor(int $games, int $partySize): int
    {
        $step = config('bowling.session_step_minutes');
        $lanes = max(1, (int) ceil($partySize / config('bowling.max_players_per_lane')));
        $perLane = (int) ceil(max(1, $partySize) / $lanes);
        $minutes = $games * $perLane * config('bowling.minutes_per_player_game');

        return max($step, (int) ceil($minutes / $step) * $step);
    }

    /**
     * The longest any booking of games can be given: the most games by a
     * full lane. Zero while games are not offered.
     */
    public function longestMinutes(): int
    {
        return $this->offersGames()
            ? $this->minutesFor(config('bowling.max_games'), config('bowling.max_players_per_lane'))
            : 0;
    }

    /**
     * What the booking forms need to offer games and show their estimate.
     *
     * @return array{mode: string, minutesPerPlayerGame: int, maxGames: int}
     */
    public function forForms(): array
    {
        return [
            'mode' => config('bowling.session_mode'),
            'minutesPerPlayerGame' => config('bowling.minutes_per_player_game'),
            'maxGames' => config('bowling.max_games'),
        ];
    }
}
