<?php

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateStaffUser extends Command implements PromptsForMissingInput
{
    use ProfileValidationRules;

    protected $signature = 'staff:create
        {name : The name of the staff member}
        {email : The email address they will log in with}
        {--admin : Give the user the admin role instead of staff}
        {--password= : The password; you are asked for it when this is left out}';

    protected $description = 'Create a user who can log in to the staff area';

    public function handle(): int
    {
        $input = [
            'name' => $this->argument('name'),
            // Fortify lowercases the email at login, so it must be stored that way.
            'email' => Str::lower($this->argument('email')),
            'password' => $this->option('password') ?? $this->secret('Password'),
        ];

        $validator = Validator::make($input, [
            ...$this->profileRules(),
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $role = $this->option('admin') ? UserRole::Admin : UserRole::Staff;

        // Staff are created by someone with server access, so their email
        // address is trusted and they can log in straight away.
        User::forceCreate([
            ...$input,
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        $this->info("Created {$role->value} user {$input['email']}.");

        return self::SUCCESS;
    }
}
