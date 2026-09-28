<div class="w-full max-w-md">
    <div class="bg-white rounded-lg shadow-md p-8">
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">{{ config('app.name') }}</h1>

        @if ($step === 'credentials')
            <form wire:submit="login" class="space-y-4">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input
                        id="email"
                        type="email"
                        wire:model="email"
                        autocomplete="username webauthn"
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('email') border-red-500 @enderror"
                    />
                    @error('email')
                        <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input
                        id="password"
                        type="password"
                        wire:model="password"
                        autocomplete="current-password"
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('password') border-red-500 @enderror"
                    />
                    @error('password')
                        <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center">
                    <input id="remember" type="checkbox" wire:model="remember" class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                    <label for="remember" class="ml-2 text-sm text-gray-600">Remember me</label>
                </div>

                <button
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-md transition-colors"
                >
                    Sign in
                </button>
            </form>

            <div x-data="{ busy: false }" x-show="window.passkeys?.supported()" class="mt-4">
                <div class="flex items-center gap-3 my-4">
                    <div class="flex-1 border-t border-gray-200"></div>
                    <span class="text-xs text-gray-400">or</span>
                    <div class="flex-1 border-t border-gray-200"></div>
                </div>
                <button
                    type="button"
                    x-on:click="busy = true; const r = await window.passkeys.authenticate(await $wire.passkeyOptions()); if (r) { await $wire.loginWithPasskey(r) } busy = false"
                    x-bind:disabled="busy"
                    class="w-full border border-gray-300 hover:bg-gray-50 text-gray-800 text-sm font-medium py-2 px-4 rounded-md transition-colors disabled:opacity-50"
                >
                    Sign in with a passkey
                </button>
                @error('passkey')
                    <p class="text-red-600 text-xs mt-2">{{ $message }}</p>
                @enderror
            </div>
        @elseif ($step === 'passkey')
            <div
                x-data="{ busy: false, async go() { this.busy = true; const r = await window.passkeys.authenticate(await $wire.passkeyOptions()); if (r) { await $wire.loginWithPasskey(r) } this.busy = false } }"
                x-init="go()"
                class="space-y-4"
            >
                <p class="text-sm text-gray-700">Confirm it's you with your passkey for {{ $maskedEmail }}.</p>
                <button
                    type="button"
                    x-on:click="go()"
                    x-bind:disabled="busy"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-md transition-colors disabled:opacity-50"
                >
                    Use passkey
                </button>
                @error('passkey')
                    <p class="text-red-600 text-xs">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-500">Lost your passkey? An administrator can remove it from the server with <code>soundboard:remove-passkeys</code>.</p>
            </div>
        @elseif ($step === 'code')
            <form wire:submit="verifyCode" class="space-y-4">
                <p class="text-sm text-gray-700">Your account needs a passkey. First, enter the code we sent to {{ $maskedEmail }}.</p>
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                    <input
                        id="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        wire:model="code"
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('code') border-red-500 @enderror"
                    />
                    @error('code')
                        <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-md transition-colors">
                    Continue
                </button>
                <button type="button" wire:click="sendCode" class="w-full text-sm text-gray-600 hover:text-gray-900">
                    Send a new code
                </button>
            </form>
        @else
            <div x-data="{ busy: false }" class="space-y-4">
                <p class="text-sm text-gray-700">Now create a passkey. You'll use it to sign in from now on.</p>
                <div>
                    <label for="passkeyName" class="block text-sm font-medium text-gray-700 mb-1">Passkey name</label>
                    <input
                        id="passkeyName"
                        type="text"
                        wire:model="passkeyName"
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('passkeyName') border-red-500 @enderror"
                    />
                    @error('passkeyName')
                        <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <button
                    type="button"
                    x-on:click="busy = true; const o = await $wire.registrationOptions(); const r = o && await window.passkeys.register(o); if (r) { await $wire.registerPasskey(r) } busy = false"
                    x-bind:disabled="busy"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded-md transition-colors disabled:opacity-50"
                >
                    Create passkey
                </button>
                @error('passkey')
                    <p class="text-red-600 text-xs">{{ $message }}</p>
                @enderror
            </div>
        @endif

        @if ($step !== 'credentials')
            <button type="button" wire:click="cancel" class="mt-6 w-full text-sm text-gray-500 hover:text-gray-800">
                Start over
            </button>
        @endif
    </div>
</div>
