<div>
    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Applications</h1>
        <div class="flex items-center gap-3">
            <input
                wire:model.live.debounce.300ms="search"
                type="search"
                placeholder="Search by name or app ID..."
                class="w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
            >
            @can('apps.create')
            <button
                wire:click="openCreate"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New App
            </button>
            @endcan
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">App ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origins</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($apps as $app)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            {{ $app->name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <code class="text-xs bg-gray-100 px-2 py-0.5 rounded">{{ $app->app_id }}</code>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            @if (count($app->allowed_origins) > 0)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    {{ count($app->allowed_origins) }} origin{{ count($app->allowed_origins) !== 1 ? 's' : '' }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800" title="Reverb rejects every connection until an origin is added">
                                    None — all connections rejected
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $app->created_at->format('M j, Y') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                @can('apps.update')
                                <button
                                    wire:click="revealCredentials({{ $app->id }})"
                                    class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                                >
                                    Credentials
                                </button>
                                <button
                                    wire:click="editApp({{ $app->id }})"
                                    class="px-3 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors"
                                >
                                    Edit
                                </button>
                                @endcan
                                @can('apps.delete')
                                <button
                                    wire:click="deleteApp({{ $app->id }})"
                                    wire:confirm="Delete this application? All clients using its credentials will be disconnected."
                                    class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors"
                                >
                                    Delete
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">
                            No applications yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $apps->links() }}
    </div>

    {{-- Create / Edit Modal --}}
    @if ($creating || $editingAppId)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div wire:click="closeModal" class="absolute inset-0 bg-black/40"></div>

            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-lg font-semibold text-gray-900">
                        {{ $creating ? 'New Application' : 'Edit Application' }}
                    </h2>
                    <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">
                    {{-- Name --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input
                            wire:model="name"
                            type="text"
                            placeholder="e.g. My App"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('name') border-red-400 @enderror"
                        >
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- App ID --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">App ID</label>
                        @if ($editingAppId)
                            <input
                                value="{{ $appId }}"
                                type="text"
                                disabled
                                class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500"
                            >
                            <p class="mt-1 text-xs text-gray-500">The App ID can't be changed after creation.</p>
                        @else
                            <input
                                wire:model="appId"
                                type="text"
                                placeholder="e.g. my-app-production"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('appId') border-red-400 @enderror"
                            >
                            <p class="mt-1 text-xs text-gray-500">Letters, numbers, dots, dashes, and underscores. Can't be changed later.</p>
                        @endif
                        @error('appId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Allowed Origins --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Allowed Origins
                            <span class="text-gray-400 font-normal">(comma-separated)</span>
                        </label>
                        <textarea
                            wire:model="allowedOrigins"
                            rows="3"
                            placeholder="e.g. app.example.com, *.example.com"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('allowedOrigins') border-red-400 @enderror"
                        ></textarea>
                        <p class="mt-1 text-xs text-gray-500">Hostnames only; wildcards like <code>*.example.com</code> work. Use <code>*</code> to allow any origin. At least one is required.</p>
                        @error('allowedOrigins') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Advanced Settings --}}
                    <div x-data="{ open: false }">
                        <button
                            x-on:click="open = !open"
                            type="button"
                            class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1"
                        >
                            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            Advanced Settings
                        </button>

                        <div x-show="open" x-collapse class="mt-3 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Ping Interval (s)</label>
                                    <input
                                        wire:model="pingInterval"
                                        type="number"
                                        min="1"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('pingInterval') border-red-400 @enderror"
                                    >
                                    @error('pingInterval') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Activity Timeout (s)</label>
                                    <input
                                        wire:model="activityTimeout"
                                        type="number"
                                        min="1"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('activityTimeout') border-red-400 @enderror"
                                    >
                                    @error('activityTimeout') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Message Size</label>
                                    <input
                                        wire:model="maxMessageSize"
                                        type="number"
                                        min="1"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('maxMessageSize') border-red-400 @enderror"
                                    >
                                    @error('maxMessageSize') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Max Connections
                                        <span class="text-gray-400 font-normal">(optional)</span>
                                    </label>
                                    <input
                                        wire:model="maxConnections"
                                        type="number"
                                        min="1"
                                        placeholder="Unlimited"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('maxConnections') border-red-400 @enderror"
                                    >
                                    @error('maxConnections') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Client Events</label>
                                <select
                                    wire:model="acceptClientEventsFrom"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('acceptClientEventsFrom') border-red-400 @enderror"
                                >
                                    <option value="members">Channel members only</option>
                                    <option value="all">Any connection</option>
                                    <option value="none">Disabled</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Who can send client events ("whispers", such as typing indicators) to a channel: only connections subscribed to it, any connection, or no one.</p>
                                @error('acceptClientEventsFrom') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div class="rounded-lg border border-gray-200 p-3 space-y-3">
                                <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                                    <input type="checkbox" wire:model="rateLimitEnabled" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                    Rate limit client messages
                                </label>
                                <div x-show="$wire.rateLimitEnabled" class="space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Max Messages</label>
                                            <input wire:model="rateLimitMaxAttempts" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('rateLimitMaxAttempts') border-red-400 @enderror">
                                            @error('rateLimitMaxAttempts') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Per (seconds)</label>
                                            <input wire:model="rateLimitDecaySeconds" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('rateLimitDecaySeconds') border-red-400 @enderror">
                                            @error('rateLimitDecaySeconds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="rateLimitTerminate" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        Disconnect clients that exceed the limit
                                    </label>
                                    <p class="text-xs text-gray-500">Counts every message a connection sends, including subscribes and client events. Over the limit, messages are rejected with an error, or with disconnect on, the connection is closed.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button
                        wire:click="closeModal"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="{{ $creating ? 'saveCreate' : 'saveEdit' }}"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                    >
                        {{ $creating ? 'Create' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Credentials Reveal Modal --}}
    @if ($credentials)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div wire:click="closeReveal" class="absolute inset-0 bg-black/40"></div>

            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-lg font-semibold text-gray-900">Application Credentials</h2>
                    <button wire:click="closeReveal" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Key</label>
                        <div x-data="{ copied: false }" class="flex items-center gap-2">
                            <code class="flex-1 block bg-gray-100 text-gray-800 text-xs rounded-lg px-3 py-2 font-mono break-all">{{ $credentials['key'] }}</code>
                            <button
                                x-on:click="navigator.clipboard.writeText({{ Js::from($credentials['key']) }}); copied = true; setTimeout(() => copied = false, 2000)"
                                class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors"
                                x-text="copied ? 'Copied!' : 'Copy'"
                            ></button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Secret</label>
                        <div x-data="{ copied: false }" class="flex items-center gap-2">
                            <code class="flex-1 block bg-gray-100 text-gray-800 text-xs rounded-lg px-3 py-2 font-mono break-all">{{ $credentials['secret'] }}</code>
                            <button
                                x-on:click="navigator.clipboard.writeText({{ Js::from($credentials['secret']) }}); copied = true; setTimeout(() => copied = false, 2000)"
                                class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors"
                                x-text="copied ? 'Copied!' : 'Copy'"
                            ></button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button
                        wire:click="closeReveal"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                    >
                        Done
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
