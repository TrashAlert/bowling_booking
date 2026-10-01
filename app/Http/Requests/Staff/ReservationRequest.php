<?php

namespace App\Http\Requests\Staff;

use App\Concerns\SessionValidationRules;
use App\Enums\LaneStatus;
use App\Models\Lane;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Making or changing a reservation. Staff choose the lanes themselves, and
 * the party size doesn't limit or decide how many there are.
 */
class ReservationRequest extends FormRequest
{
    use SessionValidationRules;

    // A sanity limit, so a typo such as 1200 isn't saved as a party size.
    public const MAX_PARTY_SIZE = 200;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'party_size' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PARTY_SIZE],
            'starts_at' => $this->startsAtRules(),
            'minutes' => $this->sessionMinutesRules(),
            'lane_ids' => ['required', 'array', 'min:1'],
            'lane_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Lane::class, 'id')->whereNull('deleted_at')->where('status', LaneStatus::Open->value),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
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
            'lane_ids' => __('lanes'),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string|Closure>
     */
    public function messages(): array
    {
        return [
            'starts_at.after_or_equal' => __('The start time can\'t be in the past.'),
            'starts_at.before_or_equal' => __('Reservations can be made up to :days days ahead.', [
                'days' => config('bowling.reservation_max_days_ahead'),
            ]),
            'lane_ids.required' => __('Pick at least one lane.'),
            'lane_ids.*.exists' => __('One of the lanes is closed or no longer exists.'),
        ];
    }

    /**
     * The lanes that were picked, lowest number first.
     *
     * @return Collection<int, Lane>
     */
    public function lanes(): Collection
    {
        return Lane::query()->whereKey($this->input('lane_ids'))->orderBy('number')->get();
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'))->utc();
    }
}
