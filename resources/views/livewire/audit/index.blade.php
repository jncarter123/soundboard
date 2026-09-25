<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Audit Log</h1>
        <div class="flex items-center gap-3">
            <select wire:model.live="event" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <option value="">All events</option>
                @foreach ($events as $group => $options)
                    <optgroup label="{{ $group }}">
                        @foreach ($options as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <input
                wire:model.live.debounce.300ms="search"
                type="search"
                placeholder="Search people, apps, details..."
                class="w-72 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
            >
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">When (UTC)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Who</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">What</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($entries as $entry)
                    @php
                        $changes = $entry->attribute_changes ?? collect();
                        $properties = $entry->properties ?? collect();
                        $new = $changes['attributes'] ?? [];
                        $old = $changes['old'] ?? [];
                    @endphp
                    <tr class="align-top">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" title="{{ $entry->created_at->toIso8601String() }}">
                            {{ $entry->created_at->format('M j, Y g:i:s A') }}
                        </td>
                        <td class="px-6 py-4 text-sm">
                            @if ($entry->causer)
                                <div class="font-medium text-gray-900">{{ $entry->causer->name }}</div>
                                <div class="text-xs text-gray-500">{{ $entry->causer->email }}</div>
                            @elseif ($entry->causer_id)
                                <span class="text-gray-500">User #{{ $entry->causer_id }} (deleted)</span>
                            @elseif (($properties['source'] ?? null) === 'cli')
                                <span class="text-gray-500">Command line</span>
                            @else
                                <span class="text-gray-500">Not signed in</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <div class="font-medium text-gray-900">{{ $entry->description }}</div>
                            @if ($label = $this->subjectLabel($entry))
                                <div class="text-xs text-gray-500">{{ $label }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <dl class="space-y-0.5">
                                @foreach ($new as $field => $value)
                                    <div>
                                        <dt class="inline font-mono text-xs text-gray-500">{{ $field }}</dt>
                                        <dd class="inline">
                                            @if (array_key_exists($field, $old))
                                                <span class="text-gray-400 line-through">{{ \App\Livewire\Audit\Index::formatValue($old[$field]) }}</span> →
                                            @endif
                                            {{ \App\Livewire\Audit\Index::formatValue($value) }}
                                        </dd>
                                    </div>
                                @endforeach
                                @foreach (['added' => 'added', 'removed' => 'removed', 'email' => 'email', 'token' => 'token', 'expires_at' => 'expires'] as $key => $label)
                                    @if (filled($properties[$key] ?? null))
                                        <div>
                                            <dt class="inline font-mono text-xs text-gray-500">{{ $label }}</dt>
                                            <dd class="inline">{{ \App\Livewire\Audit\Index::formatValue($properties[$key]) }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                            <div class="mt-1 text-xs text-gray-400">
                                {{ $properties['source'] ?? '' }}@if ($properties['ip'] ?? null) · {{ $properties['ip'] }}@endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-10 text-center text-sm text-gray-500">
                            No matching entries.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>
</div>
