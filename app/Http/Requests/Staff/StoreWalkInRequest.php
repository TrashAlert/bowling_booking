<?php

namespace App\Http\Requests\Staff;

use App\Models\Lane;
use App\Models\Package;
use App\Services\BookingService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWalkInRequest extends FormRequest
{
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
            'package_id' => ['required', 'integer', Rule::exists(Package::class, 'id')->where('is_active', true)],
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
                if ($validator->errors()->hasAny(['party_size', 'package_id'])) {
                    return;
                }

                $needed = $bookings->lanesNeeded(
                    Package::findOrFail($this->integer('package_id')),
                    $this->integer('party_size'),
                );
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
