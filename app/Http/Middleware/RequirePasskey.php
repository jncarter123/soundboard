<?php

namespace App\Http\Middleware;

use App\Passkeys\EmailCode;
use App\Passkeys\PendingLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Catches sessions that began before a passkey was required, including
 * "remember me" sign-ins: a user whose role requires a passkey and who has
 * none is signed out and sent through the email code and passkey steps.
 */
class RequirePasskey
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresPasskey() || $user->hasPasskey()) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        PendingLogin::start($user, remember: false);

        try {
            app(EmailCode::class)->send($user);
        } catch (Throwable $e) {
            Log::error('Sending sign-in code failed', ['user' => $user->id, 'message' => $e->getMessage()]);
        }

        return redirect()->route('login');
    }
}
