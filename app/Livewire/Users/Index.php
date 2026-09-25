<?php

namespace App\Livewire\Users;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public ?int $editingUserId = null;

    public bool $creating = false;

    public array $selectedRoles = [];

    public string $search = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->authorize('users.create');
        $this->reset(['editingUserId', 'name', 'email', 'password', 'password_confirmation', 'selectedRoles']);
        $this->creating = true;
    }

    public function editUser(int $userId): void
    {
        $this->authorize('users.update');
        $user = User::with('roles')->findOrFail($userId);
        $this->authorizeManageUser($user);
        $this->editingUserId = $userId;
        $this->creating = false;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->selectedRoles = $user->roles->pluck('id')->map(fn ($id) => (string) $id)->toArray();
    }

    public function saveCreate(): void
    {
        $this->authorize('users.create');
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'selectedRoles' => ['array'],
        ]);

        $roles = Role::whereIn('id', $this->selectedRoles)->get();

        if (! $this->authorizeRoleChanges(new EloquentCollection, $roles)) {
            return;
        }

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $user->syncRoles($roles);
        Audit::setChanged('user.roles_changed', 'Changed user roles', $user, [], $roles->pluck('name'));
        $this->closeModal();
    }

    public function saveEdit(): void
    {
        $this->authorize('users.update');
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', "unique:users,email,{$this->editingUserId}"],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'selectedRoles' => ['array'],
        ]);

        $user = User::with('roles')->findOrFail($this->editingUserId);
        $this->authorizeManageUser($user);

        $roles = Role::whereIn('id', $this->selectedRoles)->get();

        if (! $this->authorizeRoleChanges($user->roles, $roles, $user)) {
            return;
        }

        $user->name = $this->name;
        $user->email = $this->email;

        if ($this->password !== '') {
            $user->password = Hash::make($this->password);
        }

        $passwordChanged = $user->isDirty('password');
        $user->save();
        $previousRoles = $user->roles->pluck('name');
        $user->syncRoles($roles);
        Audit::setChanged('user.roles_changed', 'Changed user roles', $user, $previousRoles, $roles->pluck('name'));

        if ($passwordChanged) {
            Audit::log('user.password_changed', "Changed another user's password", $user);
        }
        $this->closeModal();
    }

    public function deleteUser(int $userId): void
    {
        $this->authorize('users.delete');
        $user = User::findOrFail($userId);

        if ($user->is(auth()->user())) {
            throw new AuthorizationException('You cannot delete your own account.');
        }

        $this->authorizeManageUser($user);

        if ($user->hasRole(Role::ADMIN) && User::role(Role::ADMIN)->count() <= 1) {
            throw new AuthorizationException('You cannot delete the last '.Role::ADMIN.'.');
        }

        // Sanctum doesn't remove tokens with their owner; roles are detached by Spatie.
        $user->tokens()->delete();
        $user->delete();

        if ($this->editingUserId === $userId) {
            $this->closeModal();
        }
    }

    /**
     * Editing a user's email or password effectively grants control of their
     * account, so only allow it for users with no more access than the actor.
     */
    private function authorizeManageUser(User $user): void
    {
        if (! auth()->user()->holdsAllPermissions($user->getAllPermissions())) {
            throw new AuthorizationException('You cannot manage a user with permissions you do not hold.');
        }
    }

    /**
     * Validate a role change, adding a form error and returning false if it
     * isn't allowed. Only roles being added or removed are checked.
     *
     * @param  EloquentCollection<int, Role>  $current
     * @param  EloquentCollection<int, Role>  $new
     */
    private function authorizeRoleChanges(EloquentCollection $current, EloquentCollection $new, ?User $user = null): bool
    {
        $actor = auth()->user();
        $changed = $new->diff($current)->merge($current->diff($new));

        if ($changed->isEmpty()) {
            return true;
        }

        if ($user?->is($actor)) {
            $this->addError('selectedRoles', 'You cannot change your own roles.');

            return false;
        }

        $forbidden = $changed->reject(
            fn (Role $role) => $actor->holdsAllPermissions($role->permissions)
        );

        if ($forbidden->isNotEmpty()) {
            $this->addError('selectedRoles', 'You cannot assign or remove roles with permissions you do not hold: '.$forbidden->pluck('name')->join(', ').'.');

            return false;
        }

        $removingAdmin = $current->contains(fn (Role $role) => $role->isAdmin())
            && ! $new->contains(fn (Role $role) => $role->isAdmin());

        if ($removingAdmin && User::role(Role::ADMIN)->count() <= 1) {
            $this->addError('selectedRoles', 'At least one user must keep the '.Role::ADMIN.' role.');

            return false;
        }

        return true;
    }

    public function closeModal(): void
    {
        $this->creating = false;
        $this->editingUserId = null;
        $this->reset(['name', 'email', 'password', 'password_confirmation', 'selectedRoles']);
        $this->resetValidation();
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            }))
            ->with('roles')
            ->orderBy('name')
            ->paginate(15);

        $allRoles = Role::orderBy('name')->get();

        return view('livewire.users.index', compact('users', 'allRoles'))
            ->layout('components.layouts.app');
    }
}
