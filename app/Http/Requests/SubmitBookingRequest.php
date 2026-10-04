<?php

namespace App\Http\Requests;

use App\Concerns\PhoneValidationRules;
use App\Concerns\SessionValidationRules;
use App\Http\Requests\Staff\ReservationRequest;
use App\Services\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A customer asking for a reservation from the public form. Staff call them
 * to confirm, so they must say when they can be called.
 */
class SubmitBookingRequest extends FormRequest
{
    use PhoneValidationRules, SessionValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => $this->phoneRules(),
            'party_size' => ['required', 'integer', 'min:1', 'max:'.ReservationRequest::MAX_PARTY_SIZE],
            'starts_at' => $this->startsAtRules(),
            'minutes' => $this->sessionMinutesRules(),
            'call_any_time' => ['required', 'boolean'],
            'contact_from' => ['exclude_if:call_any_time,true', 'required', 'date_format:H:i'],
            'contact_until' => ['exclude_if:call_any_time,true', 'required', 'date_format:H:i', 'after:contact_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Check the whole session falls within opening hours, once the start and
     * length are themselves valid.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['starts_at', 'minutes'])) {
                    return;
                }

                $startsAt = $this->startsAt();

                if (! app(OpeningHours::class)->coversSession($startsAt, $startsAt->addMinutes($this->integer('minutes')))) {
                    $validator->errors()->add('starts_at', __('We are not open for the whole of that session. Pick another time or a shorter session.'));
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
            'starts_at.before_or_equal' => __('Reservations can be made up to :days days ahead.', [
                'days' => config('bowling.reservation_max_days_ahead'),
            ]),
            'contact_from.required' => __('Say from when we can call you, or tick "any time".'),
            'contact_until.required' => __('Say until when we can call you, or tick "any time".'),
            'contact_until.after' => __('The end of the time to call must be after its start.'),
            ...$this->phoneMessages(),
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'))->utc();
    }

    /**
     * When the customer can be called, as "HH:MM" times, or nulls for any time.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function callWindow(): array
    {
        return $this->boolean('call_any_time')
            ? [null, null]
            : [$this->string('contact_from')->toString(), $this->string('contact_until')->toString()];
    }
}
