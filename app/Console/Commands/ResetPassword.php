<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsNewPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The way back in: Soundboard sends no email, so there is no "forgot
 * password" link. Run this on the server to set a new password.
 */
class ResetPassword extends Command
{
    use ReadsNewPassword;

    protected $signature = 'soundboard:reset-password
                            {--email= : Email address of the account}';

    protected $description = 'Set a new password for a user and sign them out everywhere';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->components->error("There is no user with the email {$email}.");

            return self::FAILURE;
        }

        $input = $this->readNewPassword();

        if ($input === null) {
            return self::FAILURE;
        }

        [$password, $generated] = $input;

        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', Password::defaults()]]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user->update(['password' => Hash::make($password)]);
        $user->signOutOtherSessions();

        $this->components->info("Reset the password for {$email} and signed them out everywhere.");

        if ($generated) {
            $this->line("  Password: <comment>{$password}</comment>");
            $this->line('  Sign in and change it on the account page.');
        }

        return self::SUCCESS;
    }
}
