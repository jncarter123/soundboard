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
