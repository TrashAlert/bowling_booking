<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A phone's browser handing over where to send its notifications: the
 * address at its push service, and the keys to encrypt them with.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * The push services of the browsers that support notifications: Chrome
     * and Edge, Firefox, Windows, and Safari. Our server posts to the address
     * it is given, so it only accepts one at these.
     *
     * @var list<string>
     */
    private const PUSH_SERVICES = [
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'push.apple.com',
    ];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => [
                'bail',
                'required',
                'string',
                'max:1000',
                'url:https',
                function (string $attribute, mixed $value, Closure $fail) {
                    $host = (string) parse_url($value, PHP_URL_HOST);

                    $known = collect(self::PUSH_SERVICES)
                        ->contains(fn (string $service) => $host === $service || Str::endsWith($host, '.'.$service));

                    if (! $known) {
                        $fail(__('We cannot send notifications to this browser.'));
                    }
                },
            ],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ];
    }
}
