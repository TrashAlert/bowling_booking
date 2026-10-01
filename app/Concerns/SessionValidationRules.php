<?php

namespace App\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait SessionValidationRules
{
    /**
     * Get the validation rules used to validate how long a session lasts.
     * The shortest session is one step long.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function sessionMinutesRules(): array
    {
        $step = config('bowling.session_step_minutes');

        return [
            'required',
            'integer',
            "min:{$step}",
            'max:'.config('bowling.max_session_minutes'),
            "multiple_of:{$step}",
        ];
    }

    /**
     * Get the validation rules used to validate how many people are in a
     * party. One entry is one lane, so a bigger group is entered once per lane.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function partySizeRules(): array
    {
        return [
            'required',
            'integer',
            'min:1',
            'max:'.config('bowling.max_players_per_lane'),
        ];
    }

    /**
     * Get the validation rules used to validate when a reservation starts:
     * not in the past, not too far ahead, and on a session step (with the
     * default 30-minute step, on the hour or half hour).
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function startsAtRules(): array
    {
        return [
            'bail',
            'required',
            'date',
            'after_or_equal:now',
            'before_or_equal:'.now()->addDays(config('bowling.reservation_max_days_ahead'))->toIso8601String(),
            function (string $attribute, mixed $value, Closure $fail) {
                $time = CarbonImmutable::parse($value);

                if ($time->second !== 0 || $time->minute % config('bowling.session_step_minutes') !== 0) {
                    $fail(__('The start time must be on the hour or half hour.'));
                }
            },
        ];
    }
}
