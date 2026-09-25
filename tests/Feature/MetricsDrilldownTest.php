<?php

namespace Tests\Feature;

use App\Livewire\Admin\Metrics;
use App\Models\ReverbApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class MetricsDrilldownTest extends TestCase
{
    use RefreshDatabase;

    protected function makeApp(): ReverbApp
    {
        return ReverbApp::create([
            'name' => 'Test App',
            'app_id' => 'app-123',
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);
    }

    protected function seedAggregates(string $appId): void
    {
        // 1-hour graph uses period=60 with 60-second buckets across the last hour.
        $now = CarbonImmutable::now();
        $currentBucket = (int) (floor($now->getTimestamp() / 60) * 60);

        // Pulse makes key_hash a generated column on MySQL, MariaDB, and
        // PostgreSQL, which rejects explicit values; only SQLite needs it set.
        $connection = DB::connection(config('pulse.storage.database.connection'));
        $setsKeyHash = $connection->getDriverName() === 'sqlite';

        $rows = [];
        foreach (range(0, 30) as $i) {
            $bucket = $currentBucket - $i * 60;
            $push = function (string $type, string $aggregate, float $value) use (&$rows, $bucket, $appId, $setsKeyHash) {
                $rows[] = [
                    'bucket' => $bucket,
                    'period' => 60,
                    'type' => $type,
                    'key' => $appId,
                    ...($setsKeyHash ? ['key_hash' => md5($appId)] : []),
                    'aggregate' => $aggregate,
                    'value' => $value,
                    'count' => 1,
                ];
            };

            $push('reverb_message:sent', 'count', ($i % 5) + 1);
            $push('reverb_message:received', 'count', ($i % 3) + 1);
            $push('reverb_connections', 'avg', 10 + $i);
            $push('reverb_connections', 'max', 15 + $i);
        }

        $connection->table('pulse_aggregates')->insert($rows);
    }

    public function test_selecting_an_app_populates_detail_time_series(): void
    {
        $app = $this->makeApp();
        $this->seedAggregates($app->app_id);

        Livewire::test(Metrics::class)
            ->set('mode', 'historical')
            ->call('selectApp', $app->app_id)
            ->assertSet('selectedApp', $app->app_id)
            ->assertCount('detailData.labels', 60)
            ->assertSet('detailData.messages.sent_total', fn ($t) => $t > 0)
            ->assertSet('detailData.messages.received_total', fn ($t) => $t > 0)
            ->assertSet('detailData.connections.peak', fn ($p) => $p >= 15)
            ->assertSeeHtml('<rect')      // message bars rendered
            ->assertSeeHtml('<polyline'); // connection lines rendered
    }

    public function test_clear_selection_resets_detail_state(): void
    {
        $app = $this->makeApp();
        $this->seedAggregates($app->app_id);

        Livewire::test(Metrics::class)
            ->set('mode', 'historical')
            ->call('selectApp', $app->app_id)
            ->call('clearSelection')
            ->assertSet('selectedApp', null)
            ->assertSet('detailData', []);
    }

    public function test_deep_link_loads_detail_on_mount(): void
    {
        $app = $this->makeApp();
        $this->seedAggregates($app->app_id);

        Livewire::withUrlParams(['app' => $app->app_id])
            ->test(Metrics::class)
            ->assertSet('mode', 'historical')
            ->assertSet('selectedApp', $app->app_id)
            ->assertCount('detailData.labels', 60);
    }

    public function test_unknown_app_id_clears_selection(): void
    {
        $this->makeApp();

        Livewire::test(Metrics::class)
            ->set('mode', 'historical')
            ->call('selectApp', 'does-not-exist')
            ->assertSet('selectedApp', null)
            ->assertSet('detailData', []);
    }
}
