<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The booking rules an admin sets on the Booking rules page. Each field is
 * named after its rule in config/bowling.php.
 */
class RulesUpdateRequest extends FormRequest
{
    // The longest that the longest session can be set to, in minutes.
    public const MAX_SESSION_CEILING = 720;

    /**
     * The smallest and largest value each rule on the page can be given,
     * for the rules whose limits never change.
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const LIMITS = [
        'waitlist_checkin_minutes' => ['min' => 2, 'max' => 60],
        'no_show_grace_minutes' => ['min' => 5, 'max' => 120],
        // Zero means a lane takes other parties right up to the start.
        'reservation_lead_minutes' => ['min' => 0, 'max' => 240],
        'check_in_opens_minutes' => ['min' => 5, 'max' => 240],
        'reservation_max_days_ahead' => ['min' => 1, 'max' => 365],
        'max_players_per_lane' => ['min' => 2, 'max' => 20],
    ];

    /**
     * The smallest and largest value each rule on the page can be given.
     * The longest session is counted in session steps, so its smallest
     * value is one step, and "step" says what it must be a multiple of.
     *
     * @return array<string, array{min: int, max: int, step?: int}>
     */
    public static function limits(): array
    {
        $step = config('bowling.session_step_minutes');

        return [
            ...self::LIMITS,
            'max_session_minutes' => ['min' => $step, 'max' => self::MAX_SESSION_CEILING, 'step' => $step],
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return collect(self::limits())
            ->map(fn (array $limits) => [
                'required',
                'integer',
                "min:{$limits['min']}",
                "max:{$limits['max']}",
                ...(isset($limits['step']) ? ["multiple_of:{$limits['step']}"] : []),
            ])
            ->all();
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'waitlist_checkin_minutes' => __('minutes to check in'),
            'no_show_grace_minutes' => __('no-show grace'),
            'reservation_lead_minutes' => __('lane closing time'),
            'check_in_opens_minutes' => __('check-in opening time'),
            'reservation_max_days_ahead' => __('days ahead'),
            'max_players_per_lane' => __('people per lane'),
            'max_session_minutes' => __('longest session'),
        ];
    }
}
