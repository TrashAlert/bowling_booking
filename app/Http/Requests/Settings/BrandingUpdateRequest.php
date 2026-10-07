<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The business name and logo, and the currency symbol and time zone it
 * works in, as an admin sets them.
 */
class BrandingUpdateRequest extends FormRequest
{
    // The biggest logo file that is accepted, in kilobytes.
    public const MAX_LOGO_KILOBYTES = 1024;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            // Photos and drawings only: an SVG can carry a script.
            'logo' => [
                'nullable',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:'.self::MAX_LOGO_KILOBYTES,
                'dimensions:max_width=2000,max_height=2000',
            ],
            'remove_logo' => ['nullable', 'boolean'],
            // Sent by the settings page with every save; a request that
            // leaves them out changes neither.
            'currency_symbol' => ['sometimes', 'required', 'string', 'max:5'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            // How customers can reach the business; each may be left empty.
            // The phone number is shown as typed, so spaces are welcome.
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/\A[0-9+()\-\s]+\z/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'map_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url:http,https'],
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
            'name' => __('business name'),
            'currency_symbol' => __('currency symbol'),
            'timezone' => __('time zone'),
            'phone' => __('phone number'),
            'map_url' => __('map link'),
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
            'logo.image' => __('The logo must be a PNG, JPG or WebP picture.'),
            'logo.mimes' => __('The logo must be a PNG, JPG or WebP picture.'),
            'logo.max' => __('The logo can be up to 1 MB.'),
            'logo.dimensions' => __('The logo can be up to 2000 pixels wide and tall.'),
            'phone.regex' => __('The phone number can only contain digits, spaces and + ( ) -.'),
            'map_url.url' => __('The map link must be a web address starting with http:// or https://.'),
        ];
    }
}
