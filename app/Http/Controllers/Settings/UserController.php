<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CreateStaffAccount;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UserStoreRequest;
use App\Http\Requests\Settings\UserUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     */
    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => $request->enum('role', UserRole::class),
        ]);

        if ($request->filled('password')) {
            $user->password = $request->string('password')->toString();
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was updated.', ['name' => $user->name])]);

        return to_route('users.index');
    }

    /**
     * Remove a user. Nobody can remove their own account, so there is always
     * an admin left.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, __('You cannot remove your own account.'));

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was removed.', ['name' => $user->name])]);

        return to_route('users.index');
    }
}
