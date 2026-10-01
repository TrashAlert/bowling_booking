<?php

namespace App\Concerns;

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
}
