<?php

namespace App\Http\Requests\Staff;

use App\Concerns\SessionValidationRules;
use App\Models\Lane;
use App\Services\BookingService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWalkInRequest extends FormRequest
{
    use SessionValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'party_size' => ['required', 'integer', 'min:1', 'max:255'],
            'minutes' => $this->sessionMinutesRules(),
        ];
    }

    /**
     * Reject a party that could never be called because it needs more lanes
     * than the venue has.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(BookingService $bookings): array
    {
        return [
            function (Validator $validator) use ($bookings) {
                if ($validator->errors()->has('party_size')) {
                    return;
                }

                $needed = $bookings->lanesNeeded($this->integer('party_size'));
                $lanes = Lane::query()->count();

                if ($needed > $lanes) {
                    $validator->errors()->add('party_size', __('A party this size needs :needed lanes, but there are only :lanes.', [
                        'needed' => $needed,
                        'lanes' => $lanes,
                    ]));
                }
            },
        ];
    }
}
