<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether customers pay a deposit to join the waitlist online, and how much,
 * as an admin sets it.
 */
class DepositUpdateRequest extends FormRequest
{
    // The smallest and largest deposit that can be set, in whole currency units.
    public const MIN_AMOUNT = 1;

    public const MAX_AMOUNT = 1000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'deposit_required' => ['required', 'boolean'],
            // Typed as money, e.g. "10" or "10.50"; kept in cents.
            'deposit_amount' => ['required', 'numeric', 'decimal:0,2', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * The deposit that was typed in, in cents.
     */
    public function depositCents(): int
    {
        return (int) round($this->float('deposit_amount') * 100);
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'deposit_required' => __('deposit choice'),
            'deposit_amount' => __('deposit amount'),
        ];
    }
}
