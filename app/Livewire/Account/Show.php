<?php

namespace App\Livewire\Account;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Every signed-in user's own name, email, and password. Needs no permission:
 * changing your own password shouldn't depend on being allowed to edit users.
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

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
    }

    public function updateProfile(): void
    {
        $user = auth()->user();
        $emailChanged = strcasecmp($this->email, $user->email) !== 0;

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

        $this->reset('currentPassword', 'password', 'password_confirmation', 'profileStatus');
        $this->passwordStatus = 'Password changed. You have been signed out everywhere else.';
    }

    public function render()
    {
        return view('livewire.account.show')
            ->layout('components.layouts.app');
    }
}
