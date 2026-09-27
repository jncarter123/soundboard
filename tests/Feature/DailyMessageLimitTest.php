<?php

namespace Tests\Feature;

use App\Livewire\Admin\Metrics;
use App\Livewire\Apps\Index as AppsIndex;
use App\Models\ReverbApp;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class DailyMessageLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminUserSeeder::class);
        Http::fake(['*' => Http::response(['connections' => 1, 'channels' => []])]);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function makeApp(array $attributes = []): ReverbApp
    {
        return ReverbApp::create([
            'name' => 'Chat',
            'app_id' => 'chat',
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
            'allowed_origins' => ['*'],
            ...$attributes,
        ]);
    }

    protected function recordMessages(string $type, int $count, int $at): void
    {
        $connection = DB::connection(config('pulse.storage.database.connection'));
        $connection->table('pulse_aggregates')->insert([
            'bucket' => (int) (floor($at / 1440) * 1440), 'period' => 1440, 'type' => $type, 'key' => 'chat',
            'aggregate' => 'count', 'value' => $count, 'count' => 1,
            ...($connection->getDriverName() === 'sqlite' ? ['key_hash' => md5('chat')] : []),
        ]);
    }

    public function test_live_metrics_show_todays_messages_against_the_limit(): void
    {
        $this->travelTo(now('UTC')->startOfDay()->addHours(12));
        $this->makeApp(['max_messages_per_day' => 2000]);
        $this->recordMessages('reverb_message:sent', 1500, now()->getTimestamp());
        $this->recordMessages('reverb_message:received', 200, now()->getTimestamp());
        $this->recordMessages('reverb_message:sent', 9999, now()->subDay()->getTimestamp());

        Livewire::actingAs($this->admin())
            ->test(Metrics::class)
            ->assertSet('messagesToday.chat', ['sent' => 1500, 'received' => 200, 'total' => 1700])
            ->assertSee('Messages Today')
            ->assertSee('1,700')
            ->assertSee('/ 2,000')
            ->assertSee('1,500 sent · 200 received')
            ->assertSee('85% of daily limit');
    }

    public function test_live_metrics_without_a_limit_show_just_the_count(): void
    {
        $this->makeApp();

        Livewire::actingAs($this->admin())
            ->test(Metrics::class)
            ->assertSee('Messages Today')
            ->assertSee('0 sent · 0 received')
            ->assertDontSee('of daily limit');
    }

    public function test_dashboard_form_saves_and_clears_the_limit(): void
    {
        $app = $this->makeApp();

        Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('editApp', $app->id)
            ->assertSet('maxMessagesPerDay', null)
            ->set('maxMessagesPerDay', 0)
            ->call('saveEdit')
            ->assertHasErrors(['maxMessagesPerDay'])
            ->set('maxMessagesPerDay', 50000)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame(50000, $app->refresh()->max_messages_per_day);

        Livewire::actingAs($this->admin())
            ->test(AppsIndex::class)
            ->call('editApp', $app->id)
            ->assertSet('maxMessagesPerDay', 50000)
            ->set('maxMessagesPerDay', null)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertNull($app->refresh()->max_messages_per_day);
    }

    public function test_api_sets_and_clears_the_limit(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/apps', ['name' => 'Chat', 'app_id' => 'chat', 'allowed_origins' => ['*'], 'max_messages_per_day' => 100000])
            ->assertCreated()
            ->assertJsonPath('data.max_messages_per_day', 100000);

        $this->patchJson('/api/apps/chat', ['max_messages_per_day' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_messages_per_day']);

        $this->patchJson('/api/apps/chat', ['max_messages_per_day' => null])
            ->assertOk()
            ->assertJsonPath('data.max_messages_per_day', null);
    }
}
