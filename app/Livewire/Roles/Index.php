<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showForm = false;

    public ?int $editingRoleId = null;

    public string $roleName = '';

    public array $selectedPermissions = [];

    public function newRole(): void
    {
        $this->authorize('roles.create');
        $this->editingRoleId = null;
        $this->roleName = '';
        $this->selectedPermissions = [];
        $this->showForm = true;
    }

    public function editRole(int $roleId): void
    {
        $this->authorize('roles.update');
        $role = Role::with('permissions')->findOrFail($roleId);
        $this->authorizeManageRole($role);
        $this->editingRoleId = $roleId;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->toArray();
        $this->showForm = true;
    }

    public function saveRole(): void
    {
        $this->authorize($this->editingRoleId ? 'roles.update' : 'roles.create');
        $this->validate([
            'roleName' => [
                'required', 'string', 'max:255', Rule::notIn([Role::ADMIN]),
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($this->editingRoleId),
            ],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', Rule::in(config('auth_permissions.permissions', []))],
        ], [
            'roleName.not_in' => 'The '.Role::ADMIN.' role name is reserved.',
        ]);

        $role = $this->editingRoleId ? Role::with('permissions')->findOrFail($this->editingRoleId) : null;
        $current = $role ? $role->permissions->pluck('name') : collect();

        if ($role) {
            $this->authorizeManageRole($role);
        }

        $changed = collect($this->selectedPermissions)->diff($current)
            ->merge($current->diff($this->selectedPermissions));

        if (! auth()->user()->holdsAllPermissions($changed)) {
            $this->addError('selectedPermissions', 'You cannot grant or revoke permissions you do not hold.');

            return;
        }

        if ($role) {
            $role->update(['name' => $this->roleName]);
        } else {
            $role = Role::create(['name' => $this->roleName, 'guard_name' => 'web']);
        }

        $role->syncPermissions($this->selectedPermissions);

        $this->cancelForm();
    }

    public function deleteRole(int $roleId): void
    {
        $this->authorize('roles.delete');
        $role = Role::with('permissions')->findOrFail($roleId);
        $this->authorizeManageRole($role);
        $role->delete();
    }

    /**
     * The Admin role is immutable, and other roles can only be changed by
     * users who already hold every permission the role grants.
     */
    private function authorizeManageRole(Role $role): void
    {
        if ($role->isAdmin()) {
            throw new AuthorizationException('The '.Role::ADMIN.' role cannot be modified.');
        }

        if (! auth()->user()->holdsAllPermissions($role->permissions)) {
            throw new AuthorizationException('You cannot modify a role with permissions you do not hold.');
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->editingRoleId = null;
        $this->roleName = '';
        $this->selectedPermissions = [];
        $this->resetValidation();
    }

    public function render()
    {
        $roles = Role::withCount('users')->with('permissions')->orderBy('name')->get();
        $availablePermissions = config('auth_permissions.permissions', []);

        return view('livewire.roles.index', compact('roles', 'availablePermissions'))
            ->layout('components.layouts.app');
    }
}
