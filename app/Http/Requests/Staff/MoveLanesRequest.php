<?php

namespace App\Http\Requests\Staff;

use App\Concerns\LaneSelectionRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Putting a reservation on different lanes without changing its time.
 */
class MoveLanesRequest extends FormRequest
{
    use LaneSelectionRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->laneSelectionRules();
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lane_ids' => __('lanes'),
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->laneSelectionMessages();
    }
}
