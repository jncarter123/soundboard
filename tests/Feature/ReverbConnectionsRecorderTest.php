<?php

namespace Tests\Feature;

use App\Models\ReverbApp;
use App\Pulse\Recorders\ReverbConnections;
use App\Services\ReverbApiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Events\IsolatedBeat;
use Laravel\Pulse\Pulse;
use Mockery;
use Tests\TestCase;

class ReverbConnectionsRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function makeApp(string $appId): ReverbApp
    {
        return ReverbApp::create([
            'name' => $appId,
            'app_id' => $appId,
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);
    }

    protected function beatAt(string $time): IsolatedBeat
    {
        return new IsolatedBeat(CarbonImmutable::parse($time));
    }

    public function test_records_connection_count_via_api_service(): void
    {
        $this->makeApp('app-a');

        $api = Mockery::mock(ReverbApiService::class);
        $api->shouldReceive('getConnectionCount')->once()->andReturn(42);

        // The recorder chains ->avg()->max()->onlyBuckets() on the returned Entry.
        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('avg')->once()->andReturnSelf();
        $entry->shouldReceive('max')->once()->andReturnSelf();
        $entry->shouldReceive('onlyBuckets')->once()->andReturnSelf();

        $pulse = Mockery::mock(Pulse::class);
        $pulse->shouldReceive('record')
            ->once()
            ->with('reverb_connections', 'app-a', 42, Mockery::type('int'))
            ->andReturn($entry);

        (new ReverbConnections($pulse, $api))->record($this->beatAt('2026-07-16 10:00:00')); // second = 0
    }

    public function test_skips_recording_when_poll_fails(): void
    {
        $this->makeApp('app-b');

        $api = Mockery::mock(ReverbApiService::class);
        $api->shouldReceive('getConnectionCount')->once()->andReturnNull();

        $pulse = Mockery::mock(Pulse::class);
        $pulse->shouldNotReceive('record'); // null poll must not record a bucket

        (new ReverbConnections($pulse, $api))->record($this->beatAt('2026-07-16 10:00:00'));
    }

    public function test_does_not_sample_off_cadence(): void
    {
        $this->makeApp('app-c');

        $api = Mockery::mock(ReverbApiService::class);
        $api->shouldNotReceive('getConnectionCount'); // second % 15 != 0 → no poll at all

        $pulse = Mockery::mock(Pulse::class);
        $pulse->shouldNotReceive('record');

        (new ReverbConnections($pulse, $api))->record($this->beatAt('2026-07-16 10:00:07')); // second = 7
    }
}
