<?php

namespace App\Http\Requests\Settings;

use App\Services\Branding;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The business name, logo and brand colour, and the currency symbol and
 * time zone it works in, as an admin sets them.
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
                // Visitors to the public demo can't put pictures online.
                ...(config('bowling.demo') ? ['prohibited'] : []),
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
            // Social media accounts, each in whatever form is natural to type;
            // each may be left empty.
            ...collect(Branding::SOCIAL)
                ->map(fn (string $key, string $network) => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:200',
                    function (string $attribute, mixed $value, Closure $fail) use ($network) {
                        if (Branding::plainSocial($network, (string) $value) === null) {
                            $fail($this->socialMessage($network));
                        }
                    },
                ])
                ->all(),
            // Empty goes back to the plain black and white.
            'brand_color' => ['sometimes', 'nullable', 'string', 'regex:'.Branding::COLOR_PATTERN],
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
     * What an admin is told when a social media account isn't one.
     */
    private function socialMessage(string $network): string
    {
        return match ($network) {
            'whatsapp' => __('The WhatsApp number must be a phone number with its country code, such as +60 12-345 6789.'),
            'instagram' => __('The Instagram account must be a name such as @kingpinbowl, or a link to it on instagram.com.'),
            'facebook' => __('The Facebook page must be a link to it on facebook.com.'),
            'tiktok' => __('The TikTok account must be a name such as @kingpinbowl, or a link to it on tiktok.com.'),
        };
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.prohibited' => __('Uploading pictures is turned off in the demo.'),
            'logo.image' => __('The logo must be a PNG, JPG or WebP picture.'),
            'logo.mimes' => __('The logo must be a PNG, JPG or WebP picture.'),
            'logo.max' => __('The logo can be up to 1 MB.'),
            'logo.dimensions' => __('The logo can be up to 2000 pixels wide and tall.'),
            'phone.regex' => __('The phone number can only contain digits, spaces and + ( ) -.'),
            'map_url.url' => __('The map link must be a web address starting with http:// or https://.'),
            'brand_color.regex' => __('The brand colour must be a colour like #1d4ed8.'),
        ];
    }
}
