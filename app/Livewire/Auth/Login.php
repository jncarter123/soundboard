<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Passkeys\EmailCode;
use App\Passkeys\PasskeyCeremony;
use App\Passkeys\PendingLogin;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * Sign-in, in up to three steps:
 *
 *  1. A password, or a passkey on its own (which counts as two factors).
 *  2. For a user who has a passkey, that passkey: a password alone is
 *     never enough once they have one.
 *  3. For a user whose role requires a passkey and who has none yet, a
 *     code sent to their email, then registering a passkey.
 *
 * Between steps nobody is signed in (see PendingLogin), so a stolen
 * password reaches nothing but the next step.
 */
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public string $code = '';

    public string $passkeyName = '';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function mount(): void
    {
        $this->passkeyName = $this->defaultPasskeyName();
    }

    public function login(): void
    {
        $this->validate(['email' => 'required|email', 'password' => 'required']);

        if (! $this->throttle($this->throttleKey())) {
            return;
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];
        $provider = Auth::guard()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
            event(new Failed('web', $user, $credentials));
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($this->throttleKey());
        $this->reset('password');

        if (! $user->hasPasskey() && ! $user->requiresPasskey()) {
            $this->complete($user, $this->remember, 'password');

            return;
        }

        PendingLogin::start($user, $this->remember);

        if (! $user->hasPasskey()) {
            $this->sendCode();
        }
    }

    /**
     * Options for the passkey step, or for signing in with a passkey alone.
     */
    public function passkeyOptions(): string
    {
        return app(PasskeyCeremony::class)->assertionOptions('login', PendingLogin::user());
    }

    public function loginWithPasskey(string $response): void
    {
        $key = 'passkey-login:'.request()->ip();

        if (! $this->throttle($key, 'passkey')) {
            return;
        }

        $pending = PendingLogin::user();
        $passkey = app(PasskeyCeremony::class)->verify($response, 'login', $pending);

        if (! $passkey) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->addError('passkey', 'That passkey was not accepted. Try again, or use a different one.');

            return;
        }

        RateLimiter::clear($key);
        $this->complete($passkey->authenticatable, $pending ? PendingLogin::remember() : $this->remember, 'passkey');
    }

    public function sendCode(): void
    {
        $user = PendingLogin::user();

        if (! $user || $user->hasPasskey()) {
            return;
        }

        try {
            if ($error = app(EmailCode::class)->send($user)) {
                $this->addError('code', $error);
            }
        } catch (Throwable $e) {
            Log::error('Sending sign-in code failed', ['user' => $user->id, 'message' => $e->getMessage()]);
            $this->addError('code', 'The code could not be sent. Ask an administrator for a passkey setup link.');
        }
    }

    public function verifyCode(): void
    {
        $user = PendingLogin::user();

        if (! $user || $user->hasPasskey()) {
            return;
        }

        $this->validate(['code' => 'required|digits:6'], ['code.digits' => 'Enter the 6-digit code.']);

        if (! app(EmailCode::class)->verify($user, $this->code)) {
            $this->addError('code', 'That code is wrong or has expired.');

            return;
        }

        $this->reset('code');
        PendingLogin::markEmailVerified();
    }

    public function registrationOptions(): ?string
    {
        $user = PendingLogin::user();

        return $user && PendingLogin::emailVerified() && ! $user->hasPasskey()
            ? app(PasskeyCeremony::class)->registrationOptions($user, 'enroll')
            : null;
    }

    public function registerPasskey(string $response): void
    {
        $user = PendingLogin::user();

        if (! $user || ! PendingLogin::emailVerified() || $user->hasPasskey()) {
            return;
        }

        $this->validate(['passkeyName' => 'required|string|max:255']);

        $passkey = app(PasskeyCeremony::class)->register($user, $response, 'enroll', $this->passkeyName);

        if (! $passkey) {
            $this->addError('passkey', 'The passkey could not be saved. Try again.');

            return;
        }

        Audit::log('passkey.added', 'Added passkey', $user, ['passkey' => $passkey->name]);
        $this->complete($user, PendingLogin::remember(), 'passkey');
    }

    public function cancel(): void
    {
        PendingLogin::clear();
        $this->resetErrorBag();
        $this->reset('password', 'code');
    }

    private function complete(User $user, bool $remember, string $method): void
    {
        PendingLogin::clear();

        Audit::$loginMethod = $method;

        try {
            Auth::login($user, $remember);
        } finally {
            Audit::$loginMethod = null;
        }

        session()->regenerate();

        $this->redirect(route('admin.home'), navigate: true);
    }

    private function throttle(string $key, string $field = 'email'): bool
    {
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError($field, 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.');

            return false;
        }

        return true;
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower($this->email)).'|'.request()->ip();
    }

    /**
     * A name for a new passkey, from the browser it's made in; the user can
     * change it.
     */
    private function defaultPasskeyName(): string
    {
        $agent = (string) request()->userAgent();

        foreach (['iPhone', 'iPad', 'Android', 'Mac', 'Windows', 'Linux'] as $platform) {
            if (str_contains($agent, $platform)) {
                return "{$platform} passkey";
            }
        }

        return 'Passkey';
    }

    public function render()
    {
        $pending = PendingLogin::user();

        return view('livewire.auth.login', [
            'step' => match (true) {
                $pending === null => 'credentials',
                $pending->hasPasskey() => 'passkey',
                PendingLogin::emailVerified() => 'register',
                default => 'code',
            },
            'maskedEmail' => $pending ? $this->mask($pending->email) : null,
        ])->layout('components.layouts.guest');
    }

    private function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return Str::substr($local, 0, 1).str_repeat('•', max(Str::length($local) - 1, 1)).'@'.$domain;
    }
}
