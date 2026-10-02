<?php

namespace App\Http\Requests;

use App\Concerns\PhoneValidationRules;
use App\Concerns\SessionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A party adding itself to the waitlist online. Unlike a walk-in added by
 * staff, it has to leave a phone number.
 */
class JoinWaitlistRequest extends FormRequest
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
            'party_size.max' => __('A lane takes up to :max people. Join again for the rest of your group.'),
            ...$this->phoneMessages(),
        ];
    }
}
