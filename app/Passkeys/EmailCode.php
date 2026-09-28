<?php

namespace App\Passkeys;

use App\Models\User;
use App\Notifications\EmailCodeNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time codes sent to a user's email, proving they control the inbox
 * before they may register a passkey. A code is stored hashed, expires
 * after ten minutes, and allows five tries; sending is rate limited.
 */
class EmailCode
{
    public const TTL_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const MAX_SENDS = 3;

    /**
     * Send a new code, replacing any earlier one. Returns an error message
     * instead of sending when rate limited.
     */
    public function send(User $user): ?string
    {
        $limit = $this->sendLimitKey($user);

        if (RateLimiter::tooManyAttempts($limit, self::MAX_SENDS)) {
            return 'Too many codes sent. Try again in '.ceil(RateLimiter::availableIn($limit) / 60).' minutes.';
        }

        RateLimiter::hit($limit, 600);

        $code = (string) random_int(100000, 999999);
        Cache::put($this->key($user), ['hash' => Hash::make($code), 'tries' => 0], now()->addMinutes(self::TTL_MINUTES));

        $user->notifyNow(new EmailCodeNotification($code, self::TTL_MINUTES));
        Audit::log('auth.email_code_sent', 'Sent sign-in code by email', $user);

        return null;
    }

    /**
     * Check a code. A correct one is used up; five wrong ones use it up too.
     */
    public function verify(User $user, string $code): bool
    {
        $entry = Cache::get($this->key($user));

        if (! $entry) {
            return false;
        }

        if (Hash::check(trim($code), $entry['hash'])) {
            Cache::forget($this->key($user));

            return true;
        }

        if (++$entry['tries'] >= self::MAX_TRIES) {
            Cache::forget($this->key($user));
            Audit::log('auth.email_code_failed', 'Too many wrong email codes', $user);
        } else {
            Cache::put($this->key($user), $entry, now()->addMinutes(self::TTL_MINUTES));
        }

        return false;
    }

    private function key(User $user): string
    {
        return "email-code:{$user->id}";
    }

    private function sendLimitKey(User $user): string
    {
        return "email-code-send:{$user->id}";
    }
}
