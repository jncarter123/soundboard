<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Performance Metrics</h1>

        <div class="flex items-center gap-3">
            {{-- Auto-refresh indicator --}}
            <div class="flex items-center gap-2 text-sm text-gray-500" title="This page refreshes automatically">
                <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                <span>
                    Auto-updating every {{ $mode === 'live' ? '5s' : '30s' }}
                    @if($lastUpdated)
                        <span class="text-gray-400">· updated {{ $lastUpdated }}</span>
                    @endif
                </span>
            </div>

            {{-- Mode Toggle --}}
            <div class="inline-flex rounded-lg border border-gray-300 bg-white p-0.5">
                <button
                    type="button"
                    wire:click="setMode('live')"
                    class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors {{ $mode === 'live' ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
                >
                    Live
                </button>
                <button
                    type="button"
                    wire:click="setMode('historical')"
                    class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors {{ $mode === 'historical' ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
                >
                    Historical
                </button>
            </div>

            @if($mode === 'live')
                <button type="button" wire:click="refreshLive" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                    Refresh
                </button>
            @endif
        </div>
    </div>

    {{-- Period Selector (Historical only) --}}
    @if($mode === 'historical')
        <div class="mb-6 flex items-center gap-4">
            @if($selectedApp)
                <button
                    type="button"
                    wire:click="clearSelection"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Back
                </button>
            @endif

            <div class="inline-flex rounded-lg border border-gray-300 bg-white p-0.5">
                @foreach($periods as $key => $label)
                    <button
                        type="button"
                        wire:click="setPeriod('{{ $key }}')"
                        class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors {{ $period === $key ? 'bg-gray-900 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Live Mode --}}
    @if($mode === 'live')
        <div wire:poll.5s="refreshLive">
            @forelse($liveData as $app)
                <div class="mb-8">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">
                        {{ $app['name'] }}
                        <span class="text-sm font-normal text-gray-500 font-mono ml-2">{{ $app['app_id'] }}</span>
                    </h2>

                    {{-- Stat Cards --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                        {{-- Connections --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <div class="flex items-center justify-between mb-1">
                                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Connections</p>
                                @if($app['connections'] !== null)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                                        Live
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                        Offline
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-3xl font-semibold text-gray-900">
                                {{ $app['connections'] !== null ? number_format($app['connections']) : '—' }}
                            </p>
                        </div>

                        {{-- Channels --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Active Channels</p>
                            <p class="mt-1 text-3xl font-semibold text-gray-900">
                                {{ $app['channels'] !== null ? number_format(count($app['channels'])) : '—' }}
                            </p>
                        </div>

                        {{-- Max Connections --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Connection Limit</p>
                            <p class="mt-1 text-3xl font-semibold text-gray-900">
                                @php
                                    $appModel = \App\Models\ReverbApp::where('app_id', $app['app_id'])->first();
                                @endphp
                                {{ $appModel && $appModel->max_connections ? number_format($appModel->max_connections) : 'Unlimited' }}
                            </p>
                        </div>
                    </div>

                    {{-- Channel List --}}
                    @if($app['channels'] !== null && count($app['channels']) > 0)
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Channel</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider" title="Presence channels count distinct members; others count connections">Subscribers</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($app['channels'] as $channelName => $channelInfo)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-3 whitespace-nowrap text-sm font-medium text-gray-900 font-mono">
                                                {{ $channelName }}
                                            </td>
                                            <td class="px-6 py-3 whitespace-nowrap text-sm">
                                                @if(str_starts_with($channelName, 'presence-'))
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Presence</span>
                                                @elseif(str_starts_with($channelName, 'private-'))
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Private</span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Public</span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600 text-right">
                                                @if(str_starts_with($channelName, 'presence-'))
                                                    @php($open = $membersOf === [$app['app_id'], $channelName])
                                                    <button
                                                        type="button"
                                                        wire:click="toggleMembers(@js($app['app_id']), @js($channelName))"
                                                        class="inline-flex items-center gap-1 text-purple-700 hover:text-purple-900"
                                                        aria-expanded="{{ $open ? 'true' : 'false' }}"
                                                    >
                                                        <span class="font-mono">{{ $channelInfo['user_count'] ?? '—' }}</span>
                                                        {{ ($channelInfo['user_count'] ?? 0) === 1 ? 'member' : 'members' }}
                                                        <svg class="w-3.5 h-3.5 transition-transform {{ $open ? 'rotate-90' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                    </button>
                                                @else
                                                    <span class="font-mono">{{ $channelInfo['subscription_count'] ?? '—' }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                        @if($membersOf === [$app['app_id'], $channelName])
                                            <tr class="bg-purple-50/50">
                                                <td colspan="3" class="px-6 py-3 text-sm">
                                                    @if($members === null)
                                                        <span class="text-red-700">Couldn't load members from Reverb.</span>
                                                    @elseif($members === [])
                                                        <span class="text-gray-500">No members.</span>
                                                    @else
                                                        <p class="text-xs text-gray-500 mb-2">Member user IDs (Reverb doesn't expose names){{ count($members) > 200 ? ', first 200 of '.count($members) : '' }}:</p>
                                                        <div class="flex flex-wrap gap-1.5">
                                                            @foreach(array_slice($members, 0, 200) as $memberId)
                                                                <span class="inline-flex px-2 py-0.5 rounded bg-white border border-purple-200 text-xs font-mono text-gray-800">{{ $memberId }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @empty
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-10 text-center">
                    <p class="text-sm text-gray-500">No Reverb applications configured.</p>
                </div>
            @endforelse
        </div>
    @endif

    {{-- Historical Mode --}}
    @if($mode === 'historical')
        <div wire:poll.30s="refreshHistorical">

        {{-- Detail view: time-series charts for one app --}}
        @if($selectedApp && $detailData)
            <div class="mb-8">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">
                    {{ $detailData['name'] }}
                    <span class="text-sm font-normal text-gray-500 font-mono ml-2">{{ $detailData['app_id'] }}</span>
                </h2>

                {{-- Summary stat cards for the selected period --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Peak Connections</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($detailData['connections']['peak']) }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Messages Sent</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($detailData['messages']['sent_total']) }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Messages Received</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($detailData['messages']['received_total']) }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Total Messages</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($detailData['messages']['sent_total'] + $detailData['messages']['received_total']) }}</p>
                    </div>
                </div>

                {{-- Messages over time --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Message Volume</h3>
                        <div class="flex items-center gap-4 text-xs font-medium text-gray-500">
                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm" style="background:#3b82f6"></span>Sent</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm" style="background:#10b981"></span>Received</span>
                        </div>
                    </div>
                    @include('livewire.admin.partials.bar-chart', [
                        'labels' => $detailData['labels'],
                        'series' => [
                            ['name' => 'Sent', 'values' => $detailData['messages']['sent'], 'color' => '#3b82f6', 'unit' => 'msgs'],
                            ['name' => 'Received', 'values' => $detailData['messages']['received'], 'color' => '#10b981', 'unit' => 'msgs'],
                        ],
                    ])
                </div>

                {{-- Connections over time --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Connections</h3>
                        <div class="flex items-center gap-4 text-xs font-medium text-gray-500">
                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm" style="background:#3b82f6"></span>Average</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm" style="background:#f59e0b"></span>Peak</span>
                        </div>
                    </div>
                    @include('livewire.admin.partials.line-chart', [
                        'labels' => $detailData['labels'],
                        'series' => [
                            ['name' => 'Average', 'values' => $detailData['connections']['avg'], 'color' => '#3b82f6', 'fill' => true, 'unit' => 'conns'],
                            ['name' => 'Peak', 'values' => $detailData['connections']['max'], 'color' => '#f59e0b', 'unit' => 'conns'],
                        ],
                    ])
                </div>
            </div>

        {{-- List view: summary cards per app (click to drill in) --}}
        @else
        @forelse($historicalData as $app)
            <button
                type="button"
                wire:click="selectApp('{{ $app['app_id'] }}')"
                class="block w-full text-left mb-8 group focus:outline-none"
            >
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    {{ $app['name'] }}
                    <span class="text-sm font-normal text-gray-500 font-mono">{{ $app['app_id'] }}</span>
                    <span class="ml-auto text-sm font-medium text-blue-600 opacity-0 group-hover:opacity-100 transition-opacity">View charts →</span>
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {{-- Avg Connections --}}
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 group-hover:border-blue-300 group-hover:shadow-md transition p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Avg Connections</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $app['avg_connections'] }}</p>
                    </div>

                    {{-- Peak Connections --}}
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 group-hover:border-blue-300 group-hover:shadow-md transition p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Peak Connections</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($app['max_connections']) }}</p>
                    </div>

                    {{-- Messages Sent --}}
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 group-hover:border-blue-300 group-hover:shadow-md transition p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Messages Sent</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($app['messages_sent']) }}</p>
                    </div>

                    {{-- Messages Received --}}
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 group-hover:border-blue-300 group-hover:shadow-md transition p-6">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider mb-1">Messages Received</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ number_format($app['messages_received']) }}</p>
                    </div>
                </div>
            </button>
        @empty
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-10 text-center">
                <p class="text-sm text-gray-500">No Reverb applications configured.</p>
            </div>
        @endforelse
        @endif

        </div>
    @endif
</div>
