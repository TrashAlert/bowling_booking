<?php

namespace App\Http\Requests\Staff;

use App\Concerns\PhoneValidationRules;
use App\Concerns\SessionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWalkInRequest extends FormRequest
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
            'phone' => $this->phoneRules(required: false),
            'party_size' => $this->partySizeRules(),
            'minutes' => $this->sessionMinutesRules(),
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
            'party_size.max' => __('A lane takes up to :max people. Add another walk-in for the rest of the group.'),
            ...$this->phoneMessages(),
        ];
    }
}
