<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Livewire\Tokens\Index as TokensIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A token acts as its owner, so tokens.manage must not let anyone hold a
 * token for a user with more access than themselves.
 */
class TokenEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_token_manager_cannot_mint_a_token_for_a_super_admin(): void
    {
        Livewire::actingAs($this->userWithPermissions(['tokens.manage']))
            ->test(TokensIndex::class)
            ->set('selectedUserId', $this->admin()->id)
            ->assertForbidden();

        $this->assertSame(0, $this->admin()->tokens()->count());
    }

    public function test_token_manager_cannot_act_for_a_user_with_more_access(): void
    {
        $manager = $this->userWithPermissions(['tokens.manage']);
        $appAdmin = $this->userWithPermissions(['apps.read', 'apps.update']);
        $teamOwner = User::factory()->create();
        Team::create(['name' => 'Payments'])->members()->attach($teamOwner, ['role' => TeamRole::Owner->value]);

        foreach ([$appAdmin, $teamOwner] as $target) {
            Livewire::actingAs($manager)->test(TokensIndex::class)
                ->set('selectedUserId', $target->id)
                ->assertForbidden();
        }
    }

    public function test_token_manager_cannot_revoke_a_more_privileged_users_token(): void
    {
        $token = $this->admin()->createToken('deploy');

        Livewire::actingAs($this->userWithPermissions(['tokens.manage']))
            ->test(TokensIndex::class)
            ->call('revokeToken', $token->accessToken->id)
            ->assertForbidden();

        $this->assertSame(1, $this->admin()->tokens()->count());
    }

    public function test_the_user_list_offers_only_users_the_manager_may_act_as(): void
    {
        $manager = $this->userWithPermissions(['tokens.manage', 'apps.read']);
        $peer = $this->userWithPermissions(['apps.read']);

        Livewire::actingAs($manager)->test(TokensIndex::class)
            ->assertSee($manager->email)
            ->assertSee($peer->email)
            ->assertDontSee('admin@example.com');
    }

    public function test_token_manager_can_still_manage_users_with_no_more_access(): void
    {
        $manager = $this->userWithPermissions(['tokens.manage', 'apps.read']);
        $peer = $this->userWithPermissions(['apps.read']);

        Livewire::actingAs($manager)->test(TokensIndex::class)
            ->set('selectedUserId', $peer->id)
            ->call('openCreateModal')
            ->set('tokenName', 'ci')
            ->call('createToken')
            ->assertHasNoErrors();

        $this->assertSame(1, $peer->tokens()->count());

        // Clearing the selection is always allowed.
        Livewire::actingAs($manager)->test(TokensIndex::class)
            ->set('selectedUserId', null)
            ->assertOk();
    }

    public function test_super_admin_can_manage_another_super_admins_tokens(): void
    {
        $other = tap(User::factory()->create())->assignRole(Role::SUPER_ADMIN);

        Livewire::actingAs($this->admin())->test(TokensIndex::class)
            ->set('selectedUserId', $other->id)
            ->call('openCreateModal')
            ->set('tokenName', 'ci')
            ->call('createToken')
            ->assertHasNoErrors();

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_holding_every_permission_does_not_make_you_a_super_admin(): void
    {
        // Super Admin passes checks beyond its assigned permissions, so
        // matching them isn't enough to take over its account.
        $superuser = $this->userWithPermissions(config('auth_permissions.permissions'));

        Livewire::actingAs($superuser)->test(UsersIndex::class)
            ->call('editUser', $this->admin()->id)
            ->assertForbidden();

        Livewire::actingAs($superuser)->test(TokensIndex::class)
            ->set('selectedUserId', $this->admin()->id)
            ->assertForbidden();
    }
}
