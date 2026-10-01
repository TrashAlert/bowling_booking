<?php

namespace App\Http\Requests\Staff;

use App\Enums\LaneClosureReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Closing a lane: why, and for how long. A short job takes a preset number
 * of minutes; a repair takes an optional estimate in days.
 */
class CloseLaneRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(LaneClosureReason::class)],
            'minutes' => [
                Rule::requiredIf(fn () => $this->reason()?->isShort() ?? false),
                'nullable',
                'integer',
                Rule::in(config('bowling.closure_minutes')),
            ],
            // 0 means no estimate: nobody knows yet when the lane will be back.
            'days' => ['nullable', 'integer', Rule::in([0, ...config('bowling.repair_days')])],
        ];
    }

    /**
     * The reason that was chosen, or null when it is missing or unknown.
     */
    public function reason(): ?LaneClosureReason
    {
        return LaneClosureReason::tryFrom((string) $this->input('reason'));
    }
}
