<?php

namespace App\Http\Requests;

use App\Concerns\ChoosesSessionLength;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A group in the line changing itself from its own page: its name, how many
 * people, and how long it plays, by the same rules as joining.
 */
class ChangeWaitlistGroupRequest extends FormRequest
{
    use ChoosesSessionLength;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'party_size' => $this->partySizeRules(),
            ...$this->sessionChoiceRules(),
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
            'party_size.max' => __('A lane takes up to :max people.'),
            ...$this->sessionChoiceMessages(),
        ];
    }
}
