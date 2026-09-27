<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Livewire\Admin\Metrics;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Teams\Index as TeamsIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use RefreshDatabase;

    private Team $payments;

    private Team $search;

    private ReverbApp $paymentsApp;

    private ReverbApp $searchApp;

    private ReverbApp $serverApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);

        $this->payments = Team::create(['name' => 'Payments']);
        $this->search = Team::create(['name' => 'Search']);
        $this->paymentsApp = $this->makeApp('payments-app', $this->payments);
        $this->searchApp = $this->makeApp('search-app', $this->search);
        $this->serverApp = $this->makeApp('server-app');
    }

    private function makeApp(string $appId, ?Team $team = null): ReverbApp
    {
        return ReverbApp::create([
            'name' => $appId,
            'app_id' => $appId,
            'team_id' => $team?->id,
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
            'allowed_origins' => ['app.example.com'],
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return tap(User::factory()->create())->assignRole($role);
    }

    // --- Super Admin -----------------------------------------------------

    public function test_the_built_in_role_is_super_admin(): void
    {
        $this->assertTrue($this->admin()->hasRole('Super Admin'));
        $this->assertNull(Role::where('name', 'Admin')->first());
    }

    public function test_super_admin_passes_permissions_its_role_does_not_hold(): void
    {
        Permission::create(['name' => 'future.permission', 'guard_name' => 'web']);

        $this->assertTrue($this->admin()->can('future.permission'));
        $this->assertFalse($this->userWithPermissions(['apps.read'])->can('future.permission'));
    }

    // --- Dashboard: apps -------------------------------------------------

    public function test_global_permission_still_sees_every_app(): void
    {
        Livewire::actingAs($this->userWithPermissions(['apps.read']))
            ->test(AppsIndex::class)
            ->assertSee('payments-app')
            ->assertSee('search-app')
            ->assertSee('server-app');
    }

    public function test_team_members_see_only_their_teams_apps(): void
    {
        $viewer = $this->member($this->payments, TeamRole::Viewer);

        $this->actingAs($viewer)->get('/admin/apps')->assertOk();

        Livewire::actingAs($viewer)
            ->test(AppsIndex::class)
            ->assertSee('payments-app')
            ->assertDontSee('search-app')
            ->assertDontSee('server-app');
    }

    public function test_users_with_no_team_and_no_permission_cannot_open_apps(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/apps')->assertForbidden();
    }

    public function test_viewers_cannot_edit_or_reveal_credentials(): void
    {
        $viewer = $this->member($this->payments, TeamRole::Viewer);

        Livewire::actingAs($viewer)->test(AppsIndex::class)
            ->call('editApp', $this->paymentsApp->id)
            ->assertForbidden();

        Livewire::actingAs($viewer)->test(AppsIndex::class)
            ->call('revealCredentials', $this->paymentsApp->id)
            ->assertForbidden();
    }

    public function test_other_teams_apps_are_not_found(): void
    {
        $maintainer = $this->member($this->payments, TeamRole::Maintainer);

        Livewire::actingAs($maintainer)->test(AppsIndex::class)
            ->call('revealCredentials', $this->searchApp->id)
            ->assertNotFound();

        Livewire::actingAs($maintainer)->test(AppsIndex::class)
            ->call('deleteApp', $this->serverApp->id)
            ->assertNotFound();
    }

    public function test_maintainers_manage_their_teams_apps_but_cannot_delete(): void
    {
        $maintainer = $this->member($this->payments, TeamRole::Maintainer);

        Livewire::actingAs($maintainer)->test(AppsIndex::class)
            ->call('revealCredentials', $this->paymentsApp->id)
            ->assertSee($this->paymentsApp->secret);

        Livewire::actingAs($maintainer)->test(AppsIndex::class)
            ->call('editApp', $this->paymentsApp->id)
            ->set('name', 'Renamed')
            ->call('saveEdit')
            ->assertHasNoErrors();
        $this->assertSame('Renamed', $this->paymentsApp->fresh()->name);

        Livewire::actingAs($maintainer)->test(AppsIndex::class)
            ->call('deleteApp', $this->paymentsApp->id)
            ->assertForbidden();
    }

    public function test_owners_can_delete_their_teams_apps(): void
    {
        Livewire::actingAs($this->member($this->payments, TeamRole::Owner))->test(AppsIndex::class)
            ->call('deleteApp', $this->paymentsApp->id)
            ->assertOk();

        $this->assertNull($this->paymentsApp->fresh());
    }

    public function test_maintainers_create_apps_only_in_their_teams(): void
    {
        $maintainer = $this->member($this->payments, TeamRole::Maintainer);

        Livewire::actingAs($maintainer)->test(AppsIndex::class)
            ->call('openCreate')
            ->assertSet('teamId', $this->payments->id)
            ->set('name', 'New')
            ->set('appId', 'new-app')
            ->set('allowedOrigins', '*')
            ->call('saveCreate')
            ->assertHasNoErrors();
        $this->assertSame($this->payments->id, ReverbApp::where('app_id', 'new-app')->value('team_id'));

        foreach ([$this->search->id, null] as $teamId) {
            Livewire::actingAs($maintainer)->test(AppsIndex::class)
                ->call('openCreate')
                ->set('teamId', $teamId)
                ->set('name', 'Elsewhere')
                ->set('appId', 'elsewhere-'.($teamId ?? 'none'))
                ->set('allowedOrigins', '*')
                ->call('saveCreate')
                ->assertForbidden();
        }
    }

    public function test_viewers_cannot_create_apps(): void
    {
        Livewire::actingAs($this->member($this->payments, TeamRole::Viewer))->test(AppsIndex::class)
            ->assertDontSee('New App')
            ->call('openCreate')
            ->assertForbidden();
    }

    public function test_only_global_apps_update_can_move_an_app_between_teams(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        $this->search->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        Livewire::actingAs($owner)->test(AppsIndex::class)
            ->call('editApp', $this->paymentsApp->id)
            ->set('teamId', $this->search->id)
            ->call('saveEdit')
            ->assertForbidden();

        Livewire::actingAs($this->userWithPermissions(['apps.read', 'apps.update']))->test(AppsIndex::class)
            ->call('editApp', $this->paymentsApp->id)
            ->set('teamId', $this->search->id)
            ->call('saveEdit')
            ->assertHasNoErrors();
        $this->assertSame($this->search->id, $this->paymentsApp->fresh()->team_id);
    }

    // --- API -------------------------------------------------------------

    public function test_api_lists_only_the_members_teams_apps(): void
    {
        Sanctum::actingAs($this->member($this->payments, TeamRole::Viewer));

        $this->getJson('/api/apps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.app_id', 'payments-app')
            ->assertJsonPath('data.0.team_id', $this->payments->id);
    }

    public function test_api_hides_other_teams_apps_and_enforces_roles(): void
    {
        Sanctum::actingAs($this->member($this->payments, TeamRole::Viewer));

        $this->getJson('/api/apps/payments-app')->assertOk();
        $this->getJson('/api/apps/search-app')->assertNotFound();
        $this->getJson('/api/apps/server-app/credentials')->assertNotFound();
        $this->patchJson('/api/apps/payments-app', ['name' => 'x'])->assertForbidden();
        $this->getJson('/api/apps/payments-app/credentials')->assertForbidden();
    }

    public function test_api_create_requires_a_team_the_member_can_create_in(): void
    {
        Sanctum::actingAs($this->member($this->payments, TeamRole::Maintainer));
        $body = ['name' => 'New', 'allowed_origins' => ['*']];

        $this->postJson('/api/apps', [...$body, 'app_id' => 'a', 'team_id' => $this->payments->id])
            ->assertCreated()
            ->assertJsonPath('data.team_id', $this->payments->id);
        $this->postJson('/api/apps', [...$body, 'app_id' => 'b', 'team_id' => $this->search->id])->assertForbidden();
        $this->postJson('/api/apps', [...$body, 'app_id' => 'c'])->assertForbidden();
    }

    public function test_api_moving_an_app_takes_the_global_permission(): void
    {
        Sanctum::actingAs($this->member($this->payments, TeamRole::Owner));
        $this->patchJson('/api/apps/payments-app', ['team_id' => null])->assertForbidden();
        $this->patchJson('/api/apps/payments-app', ['team_id' => $this->payments->id, 'name' => 'Same team'])->assertOk();

        Sanctum::actingAs($this->userWithPermissions(['apps.read', 'apps.update']));
        $this->patchJson('/api/apps/payments-app', ['team_id' => null])->assertOk()->assertJsonPath('data.team_id', null);
    }

    // --- Metrics ---------------------------------------------------------

    public function test_team_members_see_metrics_for_their_apps_only(): void
    {
        $viewer = $this->member($this->payments, TeamRole::Viewer);

        $this->actingAs($viewer)->get('/admin/metrics')->assertOk();

        $component = Livewire::actingAs($viewer)->test(Metrics::class)->call('setMode', 'historical');
        $this->assertSame(['payments-app'], array_column($component->get('historicalData'), 'app_id'));

        $component->call('selectApp', 'search-app')->assertSet('selectedApp', null);
    }

    public function test_metrics_read_still_sees_every_app(): void
    {
        $component = Livewire::actingAs($this->userWithPermissions(['metrics.read']))
            ->test(Metrics::class)
            ->call('setMode', 'historical');

        $this->assertEqualsCanonicalizing(
            ['payments-app', 'search-app', 'server-app'],
            array_column($component->get('historicalData'), 'app_id'),
        );
    }

    // --- Managing teams --------------------------------------------------

    public function test_teams_page_requires_teams_read_or_owning_a_team(): void
    {
        $this->actingAs($this->member($this->payments, TeamRole::Maintainer))->get('/admin/teams')->assertForbidden();
        $this->actingAs($this->member($this->payments, TeamRole::Owner))->get('/admin/teams')->assertOk();
        $this->actingAs($this->userWithPermissions(['teams.read']))->get('/admin/teams')->assertOk();
    }

    // --- Owners managing members -----------------------------------------

    public function test_owners_see_only_the_teams_they_own(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        $this->search->members()->attach($owner, ['role' => TeamRole::Viewer->value]);

        Livewire::actingAs($owner)->test(TeamsIndex::class)
            ->assertSee('Payments')
            ->assertDontSee('Search')
            ->assertDontSee('New Team')
            ->call('showMembers', $this->search->id)
            ->assertForbidden();
    }

    public function test_owners_manage_their_teams_members_without_global_permissions(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        $user = User::factory()->create();

        $component = Livewire::actingAs($owner)->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->set('newMemberEmail', $user->email)
            ->set('newMemberRole', 'owner')
            ->call('addMember')
            ->assertHasNoErrors();
        $this->assertSame(TeamRole::Owner, $user->fresh()->teamRole($this->payments->id));

        $component->call('changeMemberRole', $user->id, 'viewer')->assertHasNoErrors();
        $this->assertSame(TeamRole::Viewer, $user->fresh()->teamRole($this->payments->id));

        $component->call('removeMember', $user->id)->assertHasNoErrors();
        $this->assertNull($user->fresh()->teamRole($this->payments->id));
    }

    public function test_owners_cannot_rename_delete_or_create_teams(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);

        foreach ([['newTeam'], ['editTeam', $this->payments->id], ['deleteTeam', $this->payments->id]] as $call) {
            Livewire::actingAs($owner)->test(TeamsIndex::class)->call(...$call)->assertForbidden();
        }
    }

    public function test_an_owner_demoted_mid_session_loses_member_management(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        $this->member($this->payments, TeamRole::Owner);
        $viewer = $this->member($this->payments, TeamRole::Viewer);

        $component = Livewire::actingAs($owner)->test(TeamsIndex::class)->call('showMembers', $this->payments->id);

        // Demoted by someone else while the members window is still open.
        $this->payments->members()->updateExistingPivot($owner->id, ['role' => TeamRole::Viewer->value]);
        $owner->unsetRelation('teams');

        $component->call('removeMember', $viewer->id)->assertForbidden();
        $this->assertSame(TeamRole::Viewer, $viewer->fresh()->teamRole($this->payments->id));
    }

    public function test_maintainers_cannot_manage_members(): void
    {
        $maintainer = $this->member($this->payments, TeamRole::Maintainer);
        $viewer = $this->member($this->payments, TeamRole::Viewer);

        $this->actingAs($maintainer)->get('/admin/teams')->assertForbidden();

        Livewire::actingAs($maintainer)->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->assertForbidden();

        $this->assertSame(TeamRole::Viewer, $viewer->fresh()->teamRole($this->payments->id));
    }

    public function test_the_last_owner_stays_unless_a_team_manager_removes_them(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);

        $component = Livewire::actingAs($owner)->test(TeamsIndex::class)->call('showMembers', $this->payments->id);
        $component->call('changeMemberRole', $owner->id, 'maintainer')->assertHasErrors('membership');
        $component->call('removeMember', $owner->id)->assertHasErrors('membership');
        $this->assertSame(TeamRole::Owner, $owner->fresh()->teamRole($this->payments->id));

        // With a second owner, the first can step down.
        $second = $this->member($this->payments, TeamRole::Owner);
        Livewire::actingAs($owner->fresh())->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->call('removeMember', $owner->id)
            ->assertHasNoErrors();
        $this->assertNull($owner->fresh()->teamRole($this->payments->id));

        Livewire::actingAs($this->admin())->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->call('removeMember', $second->id)
            ->assertHasNoErrors();
        $this->assertSame(0, $this->payments->members()->count());
    }

    public function test_adding_by_email_reports_unknown_users_and_existing_members(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);

        Livewire::actingAs($owner)->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->set('newMemberEmail', 'nobody@example.com')
            ->call('addMember')
            ->assertHasErrors(['newMemberEmail' => 'exists'])
            ->set('newMemberEmail', $owner->email)
            ->call('addMember')
            ->assertHasErrors('newMemberEmail');
    }

    public function test_owners_do_not_see_the_user_directory(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        $stranger = User::factory()->create();

        Livewire::actingAs($owner)->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->assertDontSee($stranger->email);
    }

    public function test_super_admin_creates_a_team_and_adds_members(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($this->admin())->test(TeamsIndex::class)
            ->call('newTeam')
            ->set('teamName', 'Chat')
            ->call('saveTeam')
            ->assertHasNoErrors();

        $team = Team::where('name', 'Chat')->firstOrFail();

        $component->call('showMembers', $team->id)
            ->set('newMemberEmail', $user->email)
            ->set('newMemberRole', 'maintainer')
            ->call('addMember')
            ->assertHasNoErrors();

        $this->assertSame(TeamRole::Maintainer, $user->fresh()->teamRole($team->id));
        $this->assertDatabaseHas('activity_log', ['event' => 'team.member_added', 'subject_id' => $team->id]);

        $component->call('changeMemberRole', $user->id, 'owner');
        $this->assertSame(TeamRole::Owner, $user->fresh()->teamRole($team->id));

        $component->call('removeMember', $user->id);
        $this->assertNull($user->fresh()->teamRole($team->id));
        $this->assertSame(
            ['team.member_added', 'team.member_role_changed', 'team.member_removed'],
            Activity::where('event', 'like', 'team.member%')->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_team_roles_cannot_grant_access_the_actor_does_not_hold(): void
    {
        // Can manage teams, and holds a viewer's permissions but not an owner's.
        $manager = $this->userWithPermissions(['teams.read', 'teams.manage', 'apps.read', 'metrics.read']);
        $user = User::factory()->create();

        $component = Livewire::actingAs($manager)->test(TeamsIndex::class)
            ->call('showMembers', $this->payments->id)
            ->set('newMemberEmail', $user->email)
            ->set('newMemberRole', 'owner')
            ->call('addMember')
            ->assertHasErrors('newMemberRole');
        $this->assertNull($user->fresh()->teamRole($this->payments->id));

        // Adding themselves doesn't work either.
        $component->set('newMemberEmail', $manager->email)->set('newMemberRole', 'maintainer')->call('addMember')->assertHasErrors('newMemberRole');

        $component->set('newMemberEmail', $user->email)->set('newMemberRole', 'viewer')->call('addMember')->assertHasNoErrors();
        $component->call('changeMemberRole', $user->id, 'owner')->assertHasErrors('membership');
        $this->assertSame(TeamRole::Viewer, $user->fresh()->teamRole($this->payments->id));

        $owner = $this->member($this->payments, TeamRole::Owner);
        $component->call('removeMember', $owner->id)->assertHasErrors('membership');
        $this->assertSame(TeamRole::Owner, $owner->fresh()->teamRole($this->payments->id));
    }

    public function test_teams_with_apps_cannot_be_deleted(): void
    {
        Livewire::actingAs($this->admin())->test(TeamsIndex::class)
            ->call('deleteTeam', $this->payments->id)
            ->assertForbidden();
        $this->assertNotNull($this->payments->fresh());

        $empty = Team::create(['name' => 'Empty']);
        Livewire::actingAs($this->admin())->test(TeamsIndex::class)->call('deleteTeam', $empty->id);
        $this->assertNull($empty->fresh());
    }

    public function test_team_access_counts_when_managing_a_user(): void
    {
        // Could edit a user with no roles, but not one whose team role gives
        // app access the actor lacks: resetting their password would hand it over.
        $manager = $this->userWithPermissions(['users.read', 'users.update', 'users.delete']);
        $owner = $this->member($this->payments, TeamRole::Owner);

        Livewire::actingAs($manager)->test(UsersIndex::class)
            ->call('editUser', $owner->id)
            ->assertForbidden();

        Livewire::actingAs($manager)->test(UsersIndex::class)
            ->call('deleteUser', $owner->id)
            ->assertForbidden();

        Livewire::actingAs($manager)->test(UsersIndex::class)
            ->call('editUser', User::factory()->create()->id)
            ->assertOk();
    }

    public function test_deleting_a_user_removes_their_memberships(): void
    {
        $viewer = $this->member($this->payments, TeamRole::Viewer);
        $viewer->delete();

        $this->assertSame(0, $this->payments->members()->count());
    }
}
