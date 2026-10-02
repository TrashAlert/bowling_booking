<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

trait PhoneValidationRules
{
    /**
     * Get the validation rules used to validate a phone number: digits only,
     * with no spaces, letters or symbols. Pass false where it may be left
     * empty.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function phoneRules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:30', 'regex:/\A[0-9]+\z/'];
    }

    /**
     * Get the error messages for the phone rules.
     *
     * @return array<string, string>
     */
    protected function phoneMessages(): array
    {
        return [
            'phone.regex' => __('The phone number can only contain digits, with no spaces, letters or symbols.'),
        ];
    }
}
