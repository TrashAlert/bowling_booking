<?php

namespace App\Http\Requests\Staff;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddClosureTimeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'minutes' => ['required', 'integer', Rule::in(config('bowling.closure_minutes'))],
        ];
    }
}
