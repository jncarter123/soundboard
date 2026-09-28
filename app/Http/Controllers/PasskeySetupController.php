<?php

namespace App\Http\Controllers;

use App\Passkeys\PendingLogin;
use App\Passkeys\SetupLink;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Opens a one-time setup link: straight to registering a passkey, as if the
 * user had signed in and confirmed an email code.
 */
class PasskeySetupController extends Controller
{
    public function __invoke(string $nonce): RedirectResponse
    {
        $user = SetupLink::consume($nonce);

        abort_if($user === null, 410, 'This passkey setup link has been used or has expired.');

        // Whoever was signed in in this browser, the link is for someone else.
        if (Auth::check()) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        PendingLogin::start($user, remember: false, emailVerified: true);
        Audit::log('auth.setup_link_used', 'Opened passkey setup link', $user);

        return redirect()->route('login');
    }
}
