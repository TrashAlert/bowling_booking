<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The opening hours for every day of the week, as an admin sets them.
 */
class OpeningHoursUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.open' => ['required', 'boolean'],
            'days.*.opens' => ['exclude_unless:days.*.open,true', 'required', 'date_format:H:i'],
            'days.*.closes' => ['exclude_unless:days.*.open,true', 'required', 'date_format:H:i', 'different:days.*.opens'],
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
            'days.*.opens.required' => __('Give an opening time, or mark the day closed.'),
            'days.*.closes.required' => __('Give a closing time, or mark the day closed.'),
            'days.*.opens.date_format' => __('Give the opening time as hours and minutes.'),
            'days.*.closes.date_format' => __('Give the closing time as hours and minutes.'),
            'days.*.closes.different' => __('The closing time can\'t be the same as the opening time.'),
        ];
    }

    /**
     * The hours to save, keyed by day of the week: "HH:MM" times, or null
     * for a day the venue is closed.
     *
     * @return array<int, array{opens: string, closes: string}|null>
     */
    public function days(): array
    {
        return collect($this->validated('days'))
            ->mapWithKeys(fn (array $day) => [
                (int) $day['weekday'] => filter_var($day['open'], FILTER_VALIDATE_BOOLEAN)
                    ? ['opens' => $day['opens'], 'closes' => $day['closes']]
                    : null,
            ])
            ->all();
    }
}
