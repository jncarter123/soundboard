<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">API Tokens</h1>

        @if ($selectedUserId)
            <button
                wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New Token
            </button>
        @endif
    </div>

    {{-- User Selector (admins only) --}}
    @if ($canManageAll)
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">User</label>
            <select
                wire:model.live="selectedUserId"
                class="w-full max-w-sm rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white"
            >
                <option value="">-- Select a user --</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
        </div>
    @endif

    {{-- Tokens Table --}}
    @if ($selectedUserId)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expires</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Used</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($tokens as $token)
                        @php $expired = $token->expires_at && $token->expires_at->isPast(); @endphp
                        <tr class="hover:bg-gray-50 transition-colors {{ $expired ? 'opacity-60' : '' }}">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                {{ $token->name }}
                                @if ($expired)
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Expired</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                                {{ $token->created_at->format('M j, Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                                @if ($token->expires_at)
                                    {{ $token->expires_at->format('M j, Y') }}
                                @else
                                    <span class="text-gray-400 italic">Never</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                                @if ($token->last_used_at)
                                    {{ $token->last_used_at->diffForHumans() }}
                                @else
                                    <span class="text-gray-400 italic">Never</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <button
                                    wire:click="revokeToken({{ $token->id }})"
                                    wire:confirm="Revoke this token? Any API calls using it will stop working immediately."
                                    class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors"
                                >
                                    Revoke
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">
                                No tokens yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- Create Token Modal --}}
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div wire:click="closeCreateModal" class="absolute inset-0 bg-black/40"></div>

            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
                @if ($showNewToken)
                    {{-- Token reveal --}}
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-lg font-semibold text-gray-900">Token Created</h2>
                    </div>

                    <p class="text-sm text-gray-600 mb-3">
                        Copy this token now -- it won't be shown again.
                    </p>

                    <div
                        x-data="{ copied: false }"
                        class="flex items-center gap-2"
                    >
                        <code class="flex-1 block bg-gray-100 text-gray-800 text-xs rounded-lg px-3 py-2 font-mono break-all">{{ $newTokenValue }}</code>
                        <button
                            x-on:click="navigator.clipboard.writeText('{{ $newTokenValue }}'); copied = true; setTimeout(() => copied = false, 2000)"
                            class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors"
                            x-text="copied ? 'Copied!' : 'Copy'"
                        ></button>
                    </div>

                    <div class="flex justify-end mt-6">
                        <button
                            wire:click="closeCreateModal"
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                        >
                            Done
                        </button>
                    </div>
                @else
                    {{-- Create form --}}
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">New API Token</h2>
                            <p class="text-sm text-gray-500 mt-0.5">{{ $selectedUser?->name }}</p>
                        </div>
                        <button wire:click="closeCreateModal" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        {{-- Token Name --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Token Name</label>
                            <input
                                wire:model="tokenName"
                                type="text"
                                placeholder="e.g. Production, CI/CD, Local Dev"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            >
                            @error('tokenName')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Expiration --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Expiration</label>
                            <div class="flex gap-2 mb-2">
                                <button
                                    wire:click="setExpiryPreset('1month')"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors {{ $expiryPreset === '1month' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}"
                                >
                                    1 month
                                </button>
                                <button
                                    wire:click="setExpiryPreset('3months')"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors {{ $expiryPreset === '3months' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}"
                                >
                                    3 months
                                </button>
                                <button
                                    wire:click="setExpiryPreset('never')"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors {{ $expiryPreset === 'never' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}"
                                >
                                    Never
                                </button>
                            </div>
                            <input
                                wire:model.live="expiresAt"
                                type="date"
                                min="{{ now()->addDay()->format('Y-m-d') }}"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none {{ $expiryPreset === 'never' ? 'opacity-40' : '' }}"
                                {{ $expiryPreset === 'never' ? 'disabled' : '' }}
                            >
                            @error('expiresAt')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <button
                            wire:click="closeCreateModal"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            wire:click="createToken"
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                        >
                            Create Token
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
