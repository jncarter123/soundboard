<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen font-sans antialiased">

<nav class="bg-white border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
            <div class="flex items-center gap-6">
                <span class="font-semibold text-gray-900 text-lg">{{ config('app.name') }}</span>
                <a href="{{ route('admin.home') }}"
                   class="text-sm font-medium {{ request()->routeIs('admin.home') ? 'text-blue-600 border-b-2 border-blue-600 pb-0.5' : 'text-gray-600 hover:text-gray-900' }}">
                    Dashboard
                </a>
                {{-- Admin submenu --}}
                @if (auth()->user()->canAny(['users.read', 'roles.read', 'tokens.manage', 'tokens.manage-own', 'apps.read']))
                    @php $adminActive = request()->routeIs('admin.users', 'admin.roles', 'admin.tokens', 'admin.apps'); @endphp
                    <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                        <button @click="open = !open"
                                class="flex items-center gap-1 text-sm font-medium {{ $adminActive ? 'text-blue-600 border-b-2 border-blue-600 pb-0.5' : 'text-gray-600 hover:text-gray-900' }}">
                            Admin
                            <svg class="w-3.5 h-3.5 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="open" x-cloak
                             class="absolute top-full left-0 mt-1 w-40 bg-white border border-gray-200 rounded-md shadow-lg z-50 py-1">
                            @can('users.read')
                                <a href="{{ route('admin.users') }}"
                                   class="block px-4 py-2 text-sm {{ request()->routeIs('admin.users') ? 'text-blue-600 bg-blue-50 font-medium' : 'text-gray-700 hover:bg-gray-50' }}">
                                    Users
                                </a>
                            @endcan
                            @can('roles.read')
                                <a href="{{ route('admin.roles') }}"
                                   class="block px-4 py-2 text-sm {{ request()->routeIs('admin.roles') ? 'text-blue-600 bg-blue-50 font-medium' : 'text-gray-700 hover:bg-gray-50' }}">
                                    Roles
                                </a>
                            @endcan
                            @if (auth()->user()->canAny(['tokens.manage', 'tokens.manage-own']))
                                <a href="{{ route('admin.tokens') }}"
                                   class="block px-4 py-2 text-sm {{ request()->routeIs('admin.tokens') ? 'text-blue-600 bg-blue-50 font-medium' : 'text-gray-700 hover:bg-gray-50' }}">
                                    API Tokens
                                </a>
                            @endif
                            @can('apps.read')
                                <a href="{{ route('admin.apps') }}"
                                   class="block px-4 py-2 text-sm {{ request()->routeIs('admin.apps') ? 'text-blue-600 bg-blue-50 font-medium' : 'text-gray-700 hover:bg-gray-50' }}">
                                    Applications
                                </a>
                            @endcan
                        </div>
                    </div>
                @endif
                @can('metrics.read')
                <a href="{{ route('admin.metrics') }}"
                   class="text-sm font-medium {{ request()->routeIs('admin.metrics') ? 'text-blue-600 border-b-2 border-blue-600 pb-0.5' : 'text-gray-600 hover:text-gray-900' }}">
                    Metrics
                </a>
                @endcan
                @can('status.read')
                <a href="{{ route('admin.status') }}"
                   class="text-sm font-medium {{ request()->routeIs('admin.status') ? 'text-blue-600 border-b-2 border-blue-600 pb-0.5' : 'text-gray-600 hover:text-gray-900' }}">
                    Status
                </a>
                @endcan
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{ $slot }}
</main>

@livewireScripts
</body>
</html>
