<?php

namespace App\Passkeys;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One-time links, printed by `soundboard:passkey-link`, that let a user
 * register a passkey without an email code: whoever runs the command on
 * the server vouches for them. Valid for 15 minutes and a single use.
 */
class SetupLink
{
    public const TTL_MINUTES = 15;

    public static function create(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put(self::key($nonce), $user->id, now()->addMinutes(self::TTL_MINUTES));

        // Only the path is signed, so the link works behind a TLS-terminating
        // proxy; APP_URL supplies the scheme and host.
        $path = URL::temporarySignedRoute('passkeys.setup', now()->addMinutes(self::TTL_MINUTES), ['nonce' => $nonce], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * The link's user, if it's unused and unexpired; using it spends it.
     */
    public static function consume(string $nonce): ?User
    {
        $userId = Cache::pull(self::key($nonce));

        return $userId ? User::find($userId) : null;
    }

    private static function key(string $nonce): string
    {
        return 'passkey-setup-link:'.hash('sha256', $nonce);
    }
}
