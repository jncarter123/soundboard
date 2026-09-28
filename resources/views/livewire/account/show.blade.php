@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none')
<div class="max-w-2xl">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Your Account</h1>

    {{-- Profile --}}
    <form wire:submit="updateProfile" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Profile</h2>

        <div class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input id="name" wire:model="name" type="text" autocomplete="name" class="{{ $input }} @error('name') border-red-400 @enderror">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input id="email" wire:model="email" type="email" autocomplete="email" class="{{ $input }} @error('email') border-red-400 @enderror">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div x-show="$wire.email.toLowerCase() !== @js(strtolower(auth()->user()->email))">
                <label for="profileCurrentPassword" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                <input id="profileCurrentPassword" wire:model="profileCurrentPassword" type="password" autocomplete="current-password" class="{{ $input }} @error('profileCurrentPassword') border-red-400 @enderror">
                <p class="mt-1 text-xs text-gray-500">Required to change the email you sign in with.</p>
                @error('profileCurrentPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 mt-6">
            @if ($profileStatus)
                <p class="text-sm text-green-700">{{ $profileStatus }}</p>
            @endif
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Save Profile</button>
        </div>
    </form>

    {{-- Passkeys --}}
    <section id="passkeys" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-1">Passkeys</h2>
        <p class="text-sm text-gray-500 mb-4">
            Sign in with your fingerprint, face, or device PIN instead of a password. Once you have a passkey, your password alone no longer signs you in.
            @if (auth()->user()->requiresPasskey())
                Your role requires one.
            @endif
        </p>

        @if ($passkeys->isNotEmpty())
            <div class="border border-gray-200 rounded-lg divide-y divide-gray-200 mb-4">
                @foreach ($passkeys as $passkey)
                    <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="passkey-{{ $passkey->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $passkey->name }}</p>
                            <p class="text-xs text-gray-500">
                                Added {{ $passkey->created_at->format('M j, Y') }} ·
                                {{ $passkey->last_used_at ? 'last used '.$passkey->last_used_at->diffForHumans() : 'never used' }}
                            </p>
                        </div>
                        @if ($confirmed)
                            <button
                                wire:click="removePasskey({{ $passkey->id }})"
                                wire:confirm="Remove the passkey '{{ $passkey->name }}'?"
                                class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100"
                            >
                                Remove
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @error('passkeys') <p class="mb-3 text-xs text-red-600">{{ $message }}</p> @enderror

        @if (! $confirmed)
            {{-- Confirm it's you before changing passkeys or the email address --}}
            @if ($passkeys->isNotEmpty())
                <div x-data="{ busy: false }">
                    <button
                        type="button"
                        x-on:click="busy = true; const r = await window.passkeys.authenticate(await $wire.confirmOptions()); if (r) { await $wire.confirmWithPasskey(r) } busy = false"
                        x-bind:disabled="busy"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50"
                    >
                        Confirm with passkey to make changes
                    </button>
                </div>
            @elseif (! $confirmCodeSent)
                <button type="button" wire:click="sendConfirmCode" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                    Add a passkey
                </button>
                <p class="mt-2 text-xs text-gray-500">We'll email you a code first to confirm it's you.</p>
            @else
                <form wire:submit="confirmWithCode" class="flex items-start gap-2">
                    <div class="flex-1">
                        <input wire:model="confirmCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Code from your email" class="{{ $input }} tracking-widest @error('confirmCode') border-red-400 @enderror">
                    </div>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Confirm</button>
                </form>
                <button type="button" wire:click="sendConfirmCode" class="mt-2 text-xs text-gray-500 hover:text-gray-800">Send a new code</button>
            @endif
            @error('confirmCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('confirm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @else
            <div x-data="{ busy: false }" class="flex items-start gap-2">
                <div class="flex-1">
                    <input wire:model="newPasskeyName" type="text" placeholder="Name, e.g. MacBook" class="{{ $input }} @error('newPasskeyName') border-red-400 @enderror">
                    @error('newPasskeyName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <button
                    type="button"
                    x-on:click="busy = true; const o = await $wire.registrationOptions(); const r = o && await window.passkeys.register(o); if (r) { await $wire.addPasskey(r) } busy = false"
                    x-bind:disabled="busy"
                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >
                    Add passkey
                </button>
            </div>
            @error('confirm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @endif

        @if ($passkeyStatus)
            <p class="mt-3 text-sm text-green-700">{{ $passkeyStatus }}</p>
        @endif
    </section>

    {{-- Password --}}
    <form wire:submit="updatePassword" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Password</h2>

        <div class="space-y-4">
            <div>
                <label for="currentPassword" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                <input id="currentPassword" wire:model="currentPassword" type="password" autocomplete="current-password" class="{{ $input }} @error('currentPassword') border-red-400 @enderror">
                @error('currentPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input id="password" wire:model="password" type="password" autocomplete="new-password" class="{{ $input }} @error('password') border-red-400 @enderror">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                <input id="password_confirmation" wire:model="password_confirmation" type="password" autocomplete="new-password" class="{{ $input }}">
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 mt-6">
            @if ($passwordStatus)
                <p class="text-sm text-green-700">{{ $passwordStatus }}</p>
            @endif
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">Change Password</button>
        </div>
    </form>
</div>
