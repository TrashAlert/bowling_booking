<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\ActivityLog;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * A new email does not have to be confirmed. Staff accounts are made by
     * an admin and the venue sends no mail, so asking for confirmation here
     * would shut the user out of the staff area with no way back in.
     */
    public function update(ProfileUpdateRequest $request, ActivityLog $log): RedirectResponse
    {
        $user = $request->user()->fill($request->validated());
        $changes = collect(['name' => 'Name', 'email' => 'Email'])
            ->filter(fn (string $label, string $field) => $user->isDirty($field))
            ->mapWithKeys(fn (string $label, string $field) => [$label => [$user->getOriginal($field), $user->{$field}]])
            ->all();

        DB::transaction(function () use ($user, $changes, $log) {
            $user->save();

            if ($changes !== []) {
                $log->record('user.profile_changed', "{$user->name} changed their own profile.", $user, $changes);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
