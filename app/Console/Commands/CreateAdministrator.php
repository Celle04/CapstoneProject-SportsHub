<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAdministrator extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Create an administrator account interactively';

    public function handle(): int
    {
        $name = $this->askValidated('Full name', ['required', 'string', 'max:255']);
        $email = strtolower($this->askValidated('Email', [
            'required',
            'email',
            'max:255',
            Rule::unique('users', 'email'),
        ]));
        $username = $this->askValidated('Username', [
            'required',
            'string',
            'max:255',
            'regex:/^[A-Za-z0-9._-]+$/',
            Rule::unique('users', 'username'),
        ]);

        do {
            $password = $this->secret('Password (input hidden)');
            $passwordConfirmation = $this->secret('Confirm password');

            $validator = Validator::make([
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ], [
                'password' => PasswordRules::default(),
            ]);

            if ($validator->fails()) {
                $this->error($validator->errors()->first());
            }
        } while ($validator->fails());

        User::create([
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'role' => 'Administrator',
            'status' => 'Active',
            'password' => $password,
        ]);

        $this->info('Administrator account created. Sign in with the email or username you entered.');

        return self::SUCCESS;
    }

    /**
     * Prompt again until the value passes validation.
     *
     * @param  array<int, mixed>  $rules
     */
    private function askValidated(string $question, array $rules): string
    {
        do {
            $value = trim((string) $this->ask($question));
            $validator = Validator::make(['value' => $value], ['value' => $rules]);

            if ($validator->fails()) {
                $this->error($validator->errors()->first());
            }
        } while ($validator->fails());

        return $value;
    }
}
