<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">System Status</h1>
        <button type="button" wire:click="refresh" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
            Refresh
        </button>
    </div>

    {{-- Health Checks --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">

        {{-- Database --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Database</p>
                @if($checks['database']['up'] ?? false)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        Up
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                        Down
                    </span>
                @endif
            </div>
            <p class="text-sm text-gray-600 font-mono truncate" title="{{ $checks['database']['detail'] ?? '' }}">
                {{ $checks['database']['detail'] ?? '' }}
            </p>
        </div>

        {{-- Redis --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Redis</p>
                @if(($checks['redis']['up'] ?? null) === null)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                        N/A
                    </span>
                @elseif($checks['redis']['up'])
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        Up
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                        Down
                    </span>
                @endif
            </div>
            <p class="text-sm text-gray-600 font-mono truncate" title="{{ $checks['redis']['detail'] ?? '' }}">
                {{ $checks['redis']['detail'] ?? '' }}
            </p>
        </div>
    </div>

    {{-- Reverb Server Config --}}
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Reverb Server</h2>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Host</dt>
                    <dd class="text-sm font-medium text-gray-900 font-mono">{{ config('reverb.servers.reverb.host') }}:{{ config('reverb.servers.reverb.port') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Hostname</dt>
                    <dd class="text-sm font-medium text-gray-900 font-mono">{{ config('reverb.servers.reverb.hostname') ?: 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Scaling</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ config('reverb.servers.reverb.scaling.enabled') ? 'Enabled' : 'Disabled' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Max Request Size</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ number_format(config('reverb.servers.reverb.max_request_size', 0)) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Configured Apps --}}
    <div>
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Configured Apps</h2>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">App ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Allowed Origins</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($apps as $app)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 font-mono">
                                {{ $app['app_id'] }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                {{ $app['name'] }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($app['allowed_origins'] as $origin)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ $origin }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-10 text-center text-sm text-gray-500">
                                No apps configured.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
