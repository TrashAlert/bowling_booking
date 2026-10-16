<?php

namespace App\Http\Requests\Settings;

use App\Services\DashboardSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which parts of the staff dashboard are shown, as an admin sets them: a
 * yes or no for every part, sent as "parts[rightNow]" and so on.
 */
class DashboardPartsUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['parts' => ['required', 'array:'.implode(',', DashboardSettings::PARTS)]];

        foreach (DashboardSettings::PARTS as $part) {
            $rules["parts.{$part}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Whether each part is to be shown, keyed by part.
     *
     * @return array<string, bool>
     */
    public function shownParts(): array
    {
        return array_map(fn (mixed $shown) => filter_var($shown, FILTER_VALIDATE_BOOLEAN), $this->validated('parts'));
    }
}
