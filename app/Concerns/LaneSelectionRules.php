<?php

namespace App\Concerns;

use App\Enums\LaneStatus;
use App\Models\Lane;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * For form requests where staff tick the lanes of a reservation themselves,
 * sent as "lane_ids".
 */
trait LaneSelectionRules
{
    /**
     * Get the validation rules for the ticked lanes: at least one, each an
     * open lane, none twice.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function laneSelectionRules(): array
    {
        return [
            'lane_ids' => ['required', 'array', 'min:1'],
            'lane_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Lane::class, 'id')->whereNull('deleted_at')->where('status', LaneStatus::Open->value),
            ],
        ];
    }

    /**
     * Get the error messages for the lane rules.
     *
     * @return array<string, string>
     */
    protected function laneSelectionMessages(): array
    {
        return [
            'lane_ids.required' => __('Pick at least one lane.'),
            'lane_ids.*.exists' => __('One of the lanes is closed or no longer exists.'),
        ];
    }

    /**
     * The lanes that were ticked, lowest number first.
     *
     * @return Collection<int, Lane>
     */
    public function lanes(): Collection
    {
        return Lane::query()->whereKey($this->input('lane_ids'))->orderBy('number')->get();
    }
}
