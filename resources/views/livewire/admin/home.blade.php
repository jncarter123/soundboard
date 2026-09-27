<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Dashboard</h1>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @can('users.read')
        <a href="{{ route('admin.users') }}" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Users</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ \App\Models\User::count() }}</p>
        </a>
        @endcan
        @can('roles.read')
        <a href="{{ route('admin.roles') }}" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Roles</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ \App\Models\Role::count() }}</p>
        </a>
        @endcan
        @can('teams.read')
        <a href="{{ route('admin.teams') }}" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Teams</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ \App\Models\Team::count() }}</p>
        </a>
        @endcan
        @can('viewAny', \App\Models\ReverbApp::class)
        <a href="{{ route('admin.apps') }}" class="block bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Reverb Apps</p>
            <p class="mt-1 text-3xl font-semibold text-gray-900">{{ \App\Models\ReverbApp::visibleTo(auth()->user())->count() }}</p>
        </a>
        @endcan
    </div>
</div>
