<?php

namespace App\Passkeys;

/**
 * A signed-in user proved it's them moments ago, with a passkey or, if they
 * have none, an email code. Required before adding or removing a passkey or
 * changing the email address, so a hijacked session can't do those alone.
 */
class RecentConfirmation
{
    public const MINUTES = 5;

    private const KEY = 'passkeys.confirmed_at';

    public static function record(): void
    {
        session()->put(self::KEY, now()->getTimestamp());
    }

    public static function valid(): bool
    {
        return session(self::KEY, 0) >= now()->subMinutes(self::MINUTES)->getTimestamp();
    }
}
