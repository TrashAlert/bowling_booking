<?php

namespace App\Concerns;

use App\Services\GameSessions;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * For the forms where a group says how long it wants to play: a length of
 * time ("minutes"), a number of games ("games"), or either, as the venue
 * offers. The form must also have "party_size", since the time a number of
 * games needs depends on how many people bowl.
 */
trait ChoosesSessionLength
{
    use SessionValidationRules;

    /**
     * Get the validation rules for "minutes" and "games", as the venue
     * offers them. While both are offered, a form sends one or the other.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function sessionChoiceRules(): array
    {
        $sessions = app(GameSessions::class);
        $games = ['integer', 'min:1', 'max:'.config('bowling.max_games')];

        return match (true) {
            ! $sessions->offersGames() => [
                'minutes' => $this->sessionMinutesRules(),
                'games' => ['prohibited'],
            ],
            ! $sessions->offersTime() => [
                'games' => ['required', ...$games],
                'minutes' => ['prohibited'],
            ],
            default => [
                'games' => ['nullable', ...$games],
                'minutes' => ['exclude_with:games', ...$this->sessionMinutesRules()],
            ],
        };
    }

    /**
     * Get the error messages for the session rules.
     *
     * @return array<string, string>
     */
    protected function sessionChoiceMessages(): array
    {
        return [
            'games.required' => __('Pick how many games.'),
            'games.max' => __('A group can book up to :max games at once.'),
        ];
    }

    /**
     * How many games were booked, or null for a length of time.
     */
    public function games(): ?int
    {
        return $this->filled('games') ? $this->integer('games') : null;
    }

    /**
     * How long to keep the lanes, in minutes: the length chosen, or the
     * time the games chosen are expected to take.
     */
    public function sessionMinutes(): int
    {
        $games = $this->games();

        return $games === null
            ? $this->integer('minutes')
            : app(GameSessions::class)->minutesFor($games, $this->integer('party_size'));
    }
}
