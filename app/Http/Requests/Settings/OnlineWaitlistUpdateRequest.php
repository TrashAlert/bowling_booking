<?php

namespace App\Http\Requests\Settings;

use App\Services\WaitlistSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether customers can join the waitlist online, what they are told while
 * they cannot, and the limits on joining, as an admin sets them.
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
            // The limits on joining online. Left as they are when not sent.
            'finish_by_closing' => ['sometimes', 'boolean'],
            'max_wait_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:'.WaitlistSettings::MAX_WAIT_LIMITS['min'],
                'max:'.WaitlistSettings::MAX_WAIT_LIMITS['max'],
            ],
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
            'finish_by_closing' => __('closing time choice'),
            'max_wait_minutes' => __('longest wait'),
        ];
    }
}
