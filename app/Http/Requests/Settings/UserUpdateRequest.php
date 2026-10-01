<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UserUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Emails are stored in lowercase, so compare them that way too.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->managedUser()->id),
            'role' => ['required', Rule::enum(UserRole::class)],
            // Left blank, the user keeps their current password.
            'password' => ['nullable', 'string', Password::default()],
        ];
    }

    /**
     * An admin can't change their own role, so there is always an admin left.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->managedUser();

                if ($user->is($this->user()) && $this->input('role') !== $user->role?->value) {
                    $validator->errors()->add('role', __('You can\'t change your own role.'));
                }
            },
        ];
    }

    private function managedUser(): User
    {
        $user = $this->route('user');

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
