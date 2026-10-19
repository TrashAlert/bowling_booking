<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CreateStaffAccount;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UserStoreRequest;
use App\Http\Requests\Settings\UserUpdateRequest;
use App\Models\User;
use App\Services\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Show everyone who can log in.
     */
    public function index(): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                ]),
        ]);
    }

    /**
     * Add a user.
     */
    public function store(UserStoreRequest $request, CreateStaffAccount $createStaffAccount): RedirectResponse
    {
        $user = $createStaffAccount->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->enum('role', UserRole::class),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added.', ['name' => $user->name])]);

        return to_route('users.index');
    }

    /**
     * Change a user's details, role or password.
     *
     * An admin saving an account vouches for its email, as when creating
     * one. That also lets back in a user who was shut out as unconfirmed.
     */
    public function update(UserUpdateRequest $request, User $user, ActivityLog $log): RedirectResponse
    {
        $before = ['Name' => $user->name, 'Email' => $user->email, 'Role' => $user->role?->value];

        $user->forceFill([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => $request->enum('role', UserRole::class),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if ($request->filled('password')) {
            $user->password = $request->string('password')->toString();
        }

        DB::transaction(function () use ($user, $before, $request, $log) {
            $user->save();

            $after = ['Name' => $user->name, 'Email' => $user->email, 'Role' => $user->role?->value];
            $changes = collect($before)
                ->map(fn (?string $value, string $field) => [$value, $after[$field]])
                ->reject(fn (array $pair) => $pair[0] === $pair[1])
                // Only that it was reset, never the password itself.
                ->when($request->filled('password'), fn ($changes) => $changes->put('Password', ['old password', 'new password']))
                ->all();

            if ($changes !== []) {
                $log->record('user.changed', "Changed {$user->name}'s account.", $user, $changes);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was updated.', ['name' => $user->name])]);

        return to_route('users.index');
    }

    /**
     * Remove a user. Nobody can remove their own account, so there is always
     * an admin left.
     */
    public function destroy(Request $request, User $user, ActivityLog $log): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, __('You cannot remove your own account.'));

        DB::transaction(function () use ($user, $log) {
            $user->delete();

            $log->record('user.removed', "Removed {$user->name} ({$user->email}).", $user);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was removed.', ['name' => $user->name])]);

        return to_route('users.index');
    }
}
