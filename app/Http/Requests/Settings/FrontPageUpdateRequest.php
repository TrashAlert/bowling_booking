<?php

namespace App\Http\Requests\Settings;

use App\Services\FrontPage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The words on the front page, as an admin writes them, each of which may
 * be left empty to keep its default, and its cover photo.
 */
class FrontPageUpdateRequest extends FormRequest
{
    // The biggest cover photo that is accepted, in kilobytes.
    public const MAX_COVER_KILOBYTES = 3072;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...collect(FrontPage::TEXTS)
                ->map(fn (array $text) => ['nullable', 'string', "max:{$text['max']}"])
                ->all(),
            // Photos only: an SVG can carry a script.
            'cover' => [
                // Visitors to the public demo can't put pictures online.
                ...(config('bowling.demo') ? ['prohibited'] : []),
                'nullable',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:'.self::MAX_COVER_KILOBYTES,
                'dimensions:max_width=4000,max_height=4000',
            ],
            'remove_cover' => ['nullable', 'boolean'],
            'show_opening_hours' => ['sometimes', 'boolean'],
            'announcement' => ['sometimes', 'nullable', 'string', 'max:'.FrontPage::ANNOUNCEMENT_LENGTH],
            // A day on the venue's clock; the banner shows through its end.
            'announcement_until' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.CarbonImmutable::now(config('bowling.timezone'))->toDateString(),
            ],
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
            'cover.prohibited' => __('Uploading pictures is turned off in the demo.'),
            'cover.image' => __('The cover photo must be a PNG, JPG or WebP picture.'),
            'cover.mimes' => __('The cover photo must be a PNG, JPG or WebP picture.'),
            'cover.max' => __('The cover photo can be up to 3 MB.'),
            'cover.dimensions' => __('The cover photo can be up to 4000 pixels wide and tall.'),
            'announcement_until.after_or_equal' => __('The last day to show it can\'t be in the past.'),
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
            'tagline' => __('line under the name'),
            'waitlist_title' => __('waitlist card title'),
            'waitlist_text' => __('waitlist card description'),
            'waitlist_button' => __('waitlist card button'),
            'reserve_title' => __('reservation card title'),
            'reserve_text' => __('reservation card description'),
            'reserve_button' => __('reservation card button'),
            'announcement' => __('announcement'),
            'announcement_until' => __('last day to show it'),
        ];
    }
}
