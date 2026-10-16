<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

trait PhoneValidationRules
{
    // The fewest digits a phone number can have, so staff are not left with
    // a number they can't call back.
    public const MIN_PHONE_DIGITS = 10;

    /**
     * Get the validation rules used to validate a phone number: digits only,
     * with no spaces, letters or symbols, and at least MIN_PHONE_DIGITS of
     * them. Pass false where it may be left empty.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function phoneRules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:'.self::MIN_PHONE_DIGITS, 'max:30', 'regex:/\A[0-9]+\z/'];
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
            'phone.min' => __('The phone number must have at least :min digits.'),
        ];
    }
}
