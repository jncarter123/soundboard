<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Passkeys\SetupLink;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * For a user who can't get an email code, such as the first Super Admin
 * when passkeys are required, or when mail isn't set up.
 */
class PasskeyLink extends Command
{
    protected $signature = 'soundboard:passkey-link
                            {--email= : Email address of the account}';

    protected $description = 'Print a one-time link that lets a user register a passkey';

    public function handle(): int
    {
        return Audit::fromCommandLine(function () {
            $email = $this->option('email') ?: $this->ask('Email');
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->components->error("There is no user with the email {$email}.");

                return self::FAILURE;
            }

            $url = SetupLink::create($user);
            Audit::log('auth.setup_link_created', 'Created passkey setup link', $user);

            $this->components->info("Passkey setup link for {$user->email}, valid for ".SetupLink::TTL_MINUTES.' minutes and one use:');
            $this->line($url);
            $this->newLine();
            $this->components->warn('Anyone with this link can add a passkey to the account. Send it privately.');

            return self::SUCCESS;
        });
    }
}
