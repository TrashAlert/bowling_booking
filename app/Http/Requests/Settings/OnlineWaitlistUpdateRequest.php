<?php

namespace App\Http\Requests\Settings;

use App\Services\WaitlistSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether customers can join the waitlist online, and what they are told
 * while they cannot, as an admin sets it.
 */
class OnlineWaitlistUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'online_open' => ['required', 'boolean'],
            'closed_message' => ['nullable', 'string', 'max:'.WaitlistSettings::CLOSED_MESSAGE_LENGTH],
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
            'online_open' => __('choice'),
            'closed_message' => __('message'),
        ];
    }
}
