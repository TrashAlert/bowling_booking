<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

class CreateStaffAccount
{
    /**
     * Create a user who can log in to the staff area straight away.
     *
     * Staff are created by an admin or by someone with server access, so
     * their email address is trusted and is marked as verified.
     */
    public function handle(string $name, string $email, string $password, UserRole $role): User
    {
        return User::forceCreate([
            'name' => $name,
            // Fortify lowercases the email at login, so it must be stored that way.
            'email' => Str::lower($email),
            'password' => $password,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
