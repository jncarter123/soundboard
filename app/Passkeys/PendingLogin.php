<?php

namespace App\Passkeys;

use App\Models\User;

/**
 * A sign-in that has passed its first step but isn't finished: the user
 * gave their password and still owes a passkey, or an email code and a new
 * passkey. Nobody is signed in meanwhile, so nothing else in the app can
 * be reached. Expires after ten minutes.
 */
class PendingLogin
{
    private const KEY = 'passkeys.pending_login';

    public static function start(User $user, bool $remember, bool $emailVerified = false): void
    {
        session()->put(self::KEY, [
            'user_id' => $user->id,
            'remember' => $remember,
            'email_verified' => $emailVerified,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);
    }

    public static function user(): ?User
    {
        $pending = session(self::KEY);

        if (! $pending || $pending['expires_at'] < now()->getTimestamp()) {
            self::clear();

            return null;
        }

        return User::find($pending['user_id']);
    }

    public static function remember(): bool
    {
        return (bool) (session(self::KEY)['remember'] ?? false);
    }

    public static function emailVerified(): bool
    {
        return (bool) (session(self::KEY)['email_verified'] ?? false);
    }

    public static function markEmailVerified(): void
    {
        if (session()->has(self::KEY)) {
            session()->put(self::KEY.'.email_verified', true);
        }
    }

    public static function clear(): void
    {
        session()->forget(self::KEY);
    }
}
