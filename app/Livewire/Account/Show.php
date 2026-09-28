<?php

namespace App\Livewire\Account;

use App\Models\Passkey;
use App\Passkeys\EmailCode;
use App\Passkeys\PasskeyCeremony;
use App\Passkeys\RecentConfirmation;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Throwable;

/**
 * Every signed-in user's own name, email, password, and passkeys. Needs no
 * permission: changing your own password shouldn't depend on being allowed
 * to edit users.
 */
class Show extends Component
{
    public string $name = '';

    public string $email = '';

    public string $profileCurrentPassword = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $profileStatus = null;

    public ?string $passwordStatus = null;

    public string $confirmCode = '';

    public bool $confirmCodeSent = false;

    public string $newPasskeyName = '';

    public ?string $passkeyStatus = null;

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
    }

    public function updateProfile(): void
    {
        $user = auth()->user();
        $emailChanged = strcasecmp($this->email, $user->email) !== 0;

        // With a passkey, the password alone can't move the account to
        // another inbox: email codes go there.
        if ($emailChanged && $user->hasPasskey() && ! RecentConfirmation::valid()) {
            $this->addError('email', 'Confirm it\'s you with your passkey (under Passkeys below) to change your email.');

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            // The email is what you sign in with, so changing it needs the
            // same proof as changing the password.
            'profileCurrentPassword' => $emailChanged ? ['required', 'current_password'] : [],
        ], [
            'profileCurrentPassword.required' => 'Enter your current password to change your email.',
            'profileCurrentPassword.current_password' => 'The password is incorrect.',
        ]);

        $user->update(['name' => $this->name, 'email' => $this->email]);

        $this->reset('profileCurrentPassword', 'passwordStatus');
        $this->profileStatus = 'Profile saved.';
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'currentPassword.current_password' => 'The password is incorrect.',
        ]);

        $user = auth()->user();
        $user->update(['password' => Hash::make($this->password)]);
        $user->signOutOtherSessions(session()->getId());
        Audit::log('account.password_changed', 'Changed own password', $user);

        $this->reset('currentPassword', 'password', 'password_confirmation', 'profileStatus');
        $this->passwordStatus = 'Password changed. You have been signed out everywhere else.';
    }

    public function confirmOptions(): string
    {
        return app(PasskeyCeremony::class)->assertionOptions('confirm', auth()->user());
    }

    public function confirmWithPasskey(string $response): void
    {
        if (! app(PasskeyCeremony::class)->verify($response, 'confirm', auth()->user())) {
            $this->addError('confirm', 'That passkey was not accepted.');

            return;
        }

        RecentConfirmation::record();
    }

    /**
     * Without a passkey yet, an email code confirms it's you.
     */
    public function sendConfirmCode(): void
    {
        $user = auth()->user();

        if ($user->hasPasskey()) {
            return;
        }

        try {
            if ($error = app(EmailCode::class)->send($user)) {
                $this->addError('confirmCode', $error);

                return;
            }
        } catch (Throwable $e) {
            Log::error('Sending confirmation code failed', ['user' => $user->id, 'message' => $e->getMessage()]);
            $this->addError('confirmCode', 'The code could not be sent. Ask an administrator for a passkey setup link.');

            return;
        }

        $this->confirmCodeSent = true;
    }

    public function confirmWithCode(): void
    {
        $user = auth()->user();

        if ($user->hasPasskey()) {
            return;
        }

        $this->validate(['confirmCode' => 'required|digits:6'], ['confirmCode.digits' => 'Enter the 6-digit code.']);

        if (! app(EmailCode::class)->verify($user, $this->confirmCode)) {
            $this->addError('confirmCode', 'That code is wrong or has expired.');

            return;
        }

        $this->reset('confirmCode', 'confirmCodeSent');
        RecentConfirmation::record();
    }

    public function registrationOptions(): ?string
    {
        return RecentConfirmation::valid()
            ? app(PasskeyCeremony::class)->registrationOptions(auth()->user(), 'add')
            : null;
    }

    public function addPasskey(string $response): void
    {
        $this->passkeyStatus = null;

        if (! RecentConfirmation::valid()) {
            $this->addError('confirm', 'Confirm it\'s you first.');

            return;
        }

        $this->validate(['newPasskeyName' => 'required|string|max:255'], [], ['newPasskeyName' => 'name']);

        $user = auth()->user();
        $passkey = app(PasskeyCeremony::class)->register($user, $response, 'add', $this->newPasskeyName);

        if (! $passkey) {
            $this->addError('newPasskeyName', 'The passkey could not be saved. Try again.');

            return;
        }

        Audit::log('passkey.added', 'Added passkey', $user, ['passkey' => $passkey->name]);
        $this->reset('newPasskeyName');
        $this->passkeyStatus = 'Passkey added.';
    }

    public function removePasskey(int $id): void
    {
        $this->passkeyStatus = null;

        if (! RecentConfirmation::valid()) {
            $this->addError('confirm', 'Confirm it\'s you first.');

            return;
        }

        $user = auth()->user();
        $passkey = $user->passkeys()->findOrFail($id);

        if ($user->requiresPasskey() && $user->passkeys()->count() === 1) {
            $this->addError('passkeys', 'Your role requires a passkey. Add another before removing this one.');

            return;
        }

        $passkey->delete();
        Audit::log('passkey.removed', 'Removed passkey', $user, ['passkey' => $passkey->name]);
        $this->passkeyStatus = 'Passkey removed.';
    }

    public function render()
    {
        return view('livewire.account.show', [
            'passkeys' => auth()->user()->passkeys()->latest()->get(),
            'confirmed' => RecentConfirmation::valid(),
        ])->layout('components.layouts.app');
    }
}
