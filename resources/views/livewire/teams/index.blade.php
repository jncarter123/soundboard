<div>
    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Teams</h1>
            <p class="mt-1 text-sm text-gray-500">Members see and manage only their team's apps, according to their team role. Owners manage their team's members.</p>
        </div>
        @can('teams.manage')
        <button
            wire:click="newTeam"
            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Team
        </button>
        @endcan
    </div>

    {{-- Teams table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Members</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Apps</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($teams as $team)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $team->name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $team->members_count }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $team->apps_count }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    wire:click="showMembers({{ $team->id }})"
                                    class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                                >
                                    Members
                                </button>
                                @can('manageAlerts', $team)
                                <button
                                    wire:click="showAlerts({{ $team->id }})"
                                    class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                                >
                                    Alerts
                                </button>
                                @endcan
                                @can('teams.manage')
                                <button
                                    wire:click="editTeam({{ $team->id }})"
                                    class="px-3 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors"
                                >
                                    Rename
                                </button>
                                @if ($team->apps_count === 0)
                                <button
                                    wire:click="deleteTeam({{ $team->id }})"
                                    wire:confirm="Delete team '{{ $team->name }}'? Its members will lose access through it."
                                    class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors"
                                >
                                    Delete
                                </button>
                                @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-10 text-center text-sm text-gray-500">
                            No teams yet. Without teams, access to apps comes only from roles.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Team Form Modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div wire:click="cancelForm" class="absolute inset-0 bg-black/40"></div>

            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $editingTeamId ? 'Rename Team' : 'New Team' }}</h2>
                    <button wire:click="cancelForm" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Team Name</label>
                    <input
                        wire:model="teamName"
                        wire:keydown.enter="saveTeam"
                        type="text"
                        placeholder="e.g. Payments"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('teamName') border-red-500 @enderror"
                        autofocus
                    >
                    @error('teamName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button wire:click="cancelForm" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="saveTeam" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                        {{ $editingTeamId ? 'Save' : 'Create Team' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Members Modal --}}
    @if ($membersTeam)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div wire:click="closeMembers" class="absolute inset-0 bg-black/40"></div>

            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $membersTeam->name }}</h2>
                    <button wire:click="closeMembers" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @error('membership') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="border border-gray-200 rounded-lg divide-y divide-gray-200 max-h-80 overflow-y-auto">
                    @forelse ($membersTeam->members as $member)
                        <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="member-{{ $member->id }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $member->name }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ $member->email }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @can('manageMembers', $membersTeam)
                                    <select
                                        wire:change="changeMemberRole({{ $member->id }}, $event.target.value)"
                                        class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    >
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}" @selected($member->pivot->role === $role->value)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                    <button
                                        wire:click="removeMember({{ $member->id }})"
                                        wire:confirm="Remove {{ $member->name }} from {{ $membersTeam->name }}?"
                                        class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors"
                                    >
                                        Remove
                                    </button>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                        {{ \App\Enums\TeamRole::from($member->pivot->role)->label() }}
                                    </span>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-gray-500">No members yet.</p>
                    @endforelse
                </div>

                @can('manageMembers', $membersTeam)
                    <div class="mt-4 flex items-start gap-2">
                        <div class="flex-1">
                            <input
                                wire:model="newMemberEmail"
                                wire:keydown.enter="addMember"
                                type="email"
                                placeholder="Email of the user to add"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('newMemberEmail') border-red-400 @enderror"
                            >
                            @error('newMemberEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <select
                                wire:model="newMemberRole"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('newMemberRole') border-red-400 @enderror"
                            >
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button wire:click="addMember" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                            Add
                        </button>
                    </div>
                    @error('newMemberRole') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endcan

                <dl class="mt-5 space-y-1 text-xs text-gray-500">
                    @foreach ($roles as $role)
                        <div><dt class="inline font-medium text-gray-700">{{ $role->label() }}:</dt> <dd class="inline">{{ $role->description() }}</dd></div>
                    @endforeach
                </dl>

                <div class="mt-5">
                    <h3 class="text-sm font-medium text-gray-700 mb-1">Apps</h3>
                    @if ($membersTeam->apps->isEmpty())
                        <p class="text-sm text-gray-500">None yet. Assign apps to this team from the Applications page.</p>
                    @else
                        <div class="flex flex-wrap gap-1">
                            @foreach ($membersTeam->apps as $teamApp)
                                <code class="text-xs bg-gray-100 px-2 py-0.5 rounded">{{ $teamApp->app_id }}</code>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
    {{-- Alerts Modal --}}
    @if ($alertsTeam)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div wire:click="closeAlerts" class="absolute inset-0 bg-black/40"></div>

            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $alertsTeam->name }} alerts</h2>
                    <button wire:click="closeAlerts" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <p class="mb-5 text-sm text-gray-500">
                    Where alerts about this team's apps go: nearing or hitting a connection or daily message limit.
                    @if ($serverGetsTeamAlerts)
                        The server's own alert destinations get them too.
                    @endif
                </p>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <textarea
                            wire:model="alertMailTo"
                            rows="2"
                            placeholder="oncall@example.com, team@example.com"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('alertMailTo') border-red-400 @enderror"
                        ></textarea>
                        <p class="mt-1 text-xs text-gray-500">Comma-separated, up to 10.</p>
                        @error('alertMailTo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Webhook URL</label>
                        <input
                            wire:model="alertWebhookUrl"
                            type="url"
                            placeholder="https://example.com/hooks/soundboard"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none @error('alertWebhookUrl') border-red-400 @enderror"
                        >
                        <p class="mt-1 text-xs text-gray-500">Receives a signed JSON POST, in the same format as the server's alert webhook.</p>
                        @error('alertWebhookUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if ($alertsTeam->alert_webhook_url)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-medium text-gray-700">Signing secret</p>
                                <div class="flex gap-2">
                                    @unless ($webhookSecret)
                                        <button wire:click="revealWebhookSecret" class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                                            Show
                                        </button>
                                    @endunless
                                    <button
                                        wire:click="regenerateWebhookSecret"
                                        wire:confirm="Regenerate the secret? The receiver will reject alerts until it's updated."
                                        class="px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100"
                                    >
                                        Regenerate
                                    </button>
                                </div>
                            </div>
                            @if ($webhookSecret)
                                <div x-data="{ copied: false }" class="mt-2 flex items-center gap-2">
                                    <code class="flex-1 block bg-gray-100 text-gray-800 text-xs rounded-lg px-3 py-2 font-mono break-all">{{ $webhookSecret }}</code>
                                    <button
                                        x-on:click="navigator.clipboard.writeText({{ Js::from($webhookSecret) }}); copied = true; setTimeout(() => copied = false, 2000)"
                                        class="shrink-0 px-3 py-2 text-xs font-medium rounded-lg border border-gray-300 hover:bg-gray-50"
                                        x-text="copied ? 'Copied!' : 'Copy'"
                                    ></button>
                                </div>
                            @endif
                            <p class="mt-2 text-xs text-gray-500">Verify the <code>X-Soundboard-Signature</code> header with this secret before trusting a request.</p>
                        </div>
                    @endif

                    @error('alertTest') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($alertsSaved)
                        <p class="text-sm text-green-700">Saved.</p>
                    @endif
                    @foreach ($testResults as $result)
                        <p class="text-sm {{ $result['error'] === null ? 'text-green-700' : 'text-red-600' }}">
                            @if ($result['error'] === null)
                                Sent a test to {{ $result['target'] }}.
                            @else
                                Test to {{ $result['target'] }} failed: {{ $result['error'] }}
                            @endif
                        </p>
                    @endforeach
                </div>

                <div class="flex justify-between gap-3 mt-6">
                    <button wire:click="sendTestAlert" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                        Send test alert
                    </button>
                    <div class="flex gap-3">
                        <button wire:click="closeAlerts" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            Close
                        </button>
                        <button wire:click="saveAlerts" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700">
                            Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
