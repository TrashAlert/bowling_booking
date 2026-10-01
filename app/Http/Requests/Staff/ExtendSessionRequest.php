<?php

namespace App\Http\Requests\Staff;

use App\Concerns\SessionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExtendSessionRequest extends FormRequest
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
            // The extra time, in the same steps a session is booked in.
            'minutes' => $this->sessionMinutesRules(),
        ];
    }
}
