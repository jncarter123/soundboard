<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Livewire\Audit\Index as AuditIndex;
use App\Models\ReverbApp;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TeamAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Team $payments;

    private Team $search;

    private ReverbApp $paymentsApp;

    private ReverbApp $searchApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);

        $this->payments = Team::create(['name' => 'Payments']);
        $this->search = Team::create(['name' => 'Search']);
        $this->paymentsApp = $this->makeApp('payments-app', $this->payments);
        $this->searchApp = $this->makeApp('search-app', $this->search);
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

    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_entries_record_their_team(): void
    {
        Audit::log('credentials.viewed', 'Viewed app credentials', $this->paymentsApp);
        Audit::log('team.member_added', 'Added team member', $this->search);

        $this->assertSame($this->payments->id, Activity::where('event', 'credentials.viewed')->value('team_id'));
        $this->assertSame($this->search->id, Activity::where('event', 'team.member_added')->value('team_id'));
        $this->assertNull(Activity::where('event', 'created')->where('subject_type', User::class)->value('team_id'));
    }

    public function test_owners_see_only_their_teams_entries(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        Audit::log('credentials.viewed', 'Viewed payments credentials', $this->paymentsApp);
        Audit::log('credentials.viewed', 'Viewed search credentials', $this->searchApp);
        Audit::log('auth.login', 'Signed in', $this->admin());

        $this->actingAs($owner)->get('/admin/audit')->assertOk();

        Livewire::actingAs($owner)->test(AuditIndex::class)
            ->assertSee('Viewed payments credentials')
            ->assertDontSee('Viewed search credentials')
            ->assertDontSee('Signed in')
            ->assertSee('Activity on the apps and memberships of teams you own.');
    }

    public function test_audit_read_still_sees_everything(): void
    {
        Audit::log('credentials.viewed', 'Viewed search credentials', $this->searchApp);
        Audit::log('auth.login', 'Signed in', $this->admin());

        Livewire::actingAs($this->admin())->test(AuditIndex::class)
            ->assertSee('Viewed search credentials')
            ->assertSee('Signed in')
            ->assertDontSee('Activity on the apps and memberships of teams you own.');
    }

    public function test_only_owners_get_the_team_audit_log(): void
    {
        foreach ([TeamRole::Maintainer, TeamRole::Viewer] as $role) {
            $this->actingAs($this->member($this->payments, $role))->get('/admin/audit')->assertForbidden();
        }
    }

    public function test_history_survives_deleting_the_app(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        Audit::log('credentials.viewed', 'Viewed payments credentials', $this->paymentsApp);
        $this->paymentsApp->delete();

        Livewire::actingAs($owner)->test(AuditIndex::class)
            ->assertSee('Viewed payments credentials')
            ->assertSee('Deleted app');
    }

    public function test_owners_do_not_see_client_ips_or_search_by_them(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        Audit::log('credentials.viewed', 'Viewed payments credentials', $this->paymentsApp);
        DB::table('activity_log')->where('event', 'credentials.viewed')->update(['properties' => json_encode(['source' => 'web', 'ip' => '203.0.113.9'])]);

        Livewire::actingAs($owner)->test(AuditIndex::class)
            ->assertSee('Viewed payments credentials')
            ->assertDontSee('203.0.113.9')
            ->set('search', '203.0.113')
            ->assertDontSee('Viewed payments credentials');

        Livewire::actingAs($this->admin())->test(AuditIndex::class)
            ->assertSee('203.0.113.9')
            ->set('search', '203.0.113')
            ->assertSee('Viewed payments credentials');
    }

    public function test_owners_filter_by_team_events_only(): void
    {
        $component = Livewire::actingAs($this->member($this->payments, TeamRole::Owner))->test(AuditIndex::class);

        $events = $component->viewData('events');
        $this->assertSame(AuditIndex::TEAM_EVENT_GROUPS, array_keys($events));
    }

    public function test_search_cannot_escape_the_team_filter(): void
    {
        $owner = $this->member($this->payments, TeamRole::Owner);
        Audit::log('credentials.viewed', 'Viewed search credentials', $this->searchApp);

        Livewire::actingAs($owner)->test(AuditIndex::class)
            ->set('search', 'search')
            ->assertDontSee('Viewed search credentials');
    }

    public function test_backfill_assigns_existing_entries_to_teams(): void
    {
        Audit::log('credentials.viewed', 'Viewed payments credentials', $this->paymentsApp);
        Audit::log('team.member_added', 'Added team member', $this->search);
        DB::table('activity_log')->update(['team_id' => null]);

        $migration = require database_path('migrations/2026_09_28_000001_add_team_id_to_activity_log.php');
        $migration->down();
        $migration->up();

        $this->assertSame($this->payments->id, (int) Activity::where('event', 'credentials.viewed')->value('team_id'));
        $this->assertSame($this->search->id, (int) Activity::where('event', 'team.member_added')->value('team_id'));
        $this->assertNull(Activity::where('event', 'created')->where('subject_type', Role::class)->value('team_id'));
    }
}
