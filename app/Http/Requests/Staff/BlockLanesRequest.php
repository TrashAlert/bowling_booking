<?php

namespace App\Http\Requests\Staff;

use App\Concerns\LaneSelectionRules;
use App\Concerns\SessionValidationRules;
use App\Services\LaneBlocks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Blocking lanes for a league night or an event: which lanes, from when and
 * for how long, what for, and for how many weeks in a row.
 */
class BlockLanesRequest extends FormRequest
{
    use LaneSelectionRules, SessionValidationRules;

    // The longest a block can last: a whole day.
    public const MAX_MINUTES = 24 * 60;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $step = config('bowling.session_step_minutes');

        return [
            'note' => ['required', 'string', 'max:100'],
            'starts_at' => $this->startsAtRules(),
            'minutes' => ['required', 'integer', "min:{$step}", 'max:'.self::MAX_MINUTES, "multiple_of:{$step}"],
            'weeks' => ['required', 'integer', 'min:1', 'max:'.LaneBlocks::MAX_WEEKS],
            ...$this->laneSelectionRules(),
        ];
    }

    /**
     * Check the last week still falls within how far ahead lanes can be
     * booked, once the start and the weeks are themselves valid.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['starts_at', 'weeks'])) {
                    return;
                }

                $last = $this->startsAt()->addWeeks($this->integer('weeks') - 1);

                if ($last->greaterThan(now()->addDays(config('bowling.reservation_max_days_ahead')))) {
                    $validator->errors()->add('weeks', __('The last week must be within :days days from today.', [
                        'days' => config('bowling.reservation_max_days_ahead'),
                    ]));
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_at' => __('start time'),
            'minutes' => __('length'),
            'lane_ids' => __('lanes'),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.after_or_equal' => __('The start time can\'t be in the past.'),
            'starts_at.before_or_equal' => __('Lanes can be blocked up to :days days ahead.', [
                'days' => config('bowling.reservation_max_days_ahead'),
            ]),
            'note.required' => __('Say what the lanes are blocked for, e.g. "League night".'),
            'minutes.max' => __('A block can last a day at most.'),
            ...$this->laneSelectionMessages(),
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'))->utc();
    }
}
