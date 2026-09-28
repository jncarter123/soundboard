<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * The way back in after losing a passkey. The user signs in with their
 * password next; if their role requires a passkey, they register a new one.
 */
class RemovePasskeys extends Command
{
    protected $signature = 'soundboard:remove-passkeys
                            {--email= : Email address of the account}';

    protected $description = 'Remove all of a user\'s passkeys, for a lost device';

    public function handle(): int
    {
        return Audit::fromCommandLine(function () {
            $email = $this->option('email') ?: $this->ask('Email');
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->components->error("There is no user with the email {$email}.");

                return self::FAILURE;
            }

            $names = $user->passkeys()->pluck('name');

            if ($names->isEmpty()) {
                $this->components->info("{$user->email} has no passkeys.");

                return self::SUCCESS;
            }

            $user->passkeys()->delete();
            $user->signOutOtherSessions();
            Audit::log('passkey.removed', 'Removed passkeys (CLI)', $user, ['passkey' => $names->all()]);

            $this->components->info("Removed {$names->count()} passkey(s) from {$user->email} and signed them out everywhere.");

            return self::SUCCESS;
        });
    }
}
