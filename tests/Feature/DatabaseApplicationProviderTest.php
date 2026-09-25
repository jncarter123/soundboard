<?php

namespace Tests\Feature;

use App\Models\ReverbApp;
use App\Providers\DatabaseApplicationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;
use Tests\TestCase;

class DatabaseApplicationProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function makeApp(string $appId): ReverbApp
    {
        return ReverbApp::create([
            'name' => $appId,
            'app_id' => $appId,
            'key' => 'key-'.$appId,
            'secret' => ReverbApp::generateSecret(),
            'allowed_origins' => ['*'],
        ]);
    }

    protected function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_is_the_configured_reverb_provider(): void
    {
        $this->assertInstanceOf(DatabaseApplicationProvider::class, app(ApplicationProvider::class));
    }

    public function test_lookups_are_served_from_memory_within_ttl(): void
    {
        $this->makeApp('a');
        $provider = new DatabaseApplicationProvider(ttl: 60);

        $queries = $this->countQueries(function () use ($provider) {
            foreach (range(1, 20) as $i) {
                $provider->findByKey('key-a');
                $provider->findById('a');
            }
        });

        $this->assertSame(1, $queries);
    }

    public function test_unknown_key_throws(): void
    {
        $this->makeApp('a');

        $this->expectException(InvalidApplication::class);

        (new DatabaseApplicationProvider(ttl: 60))->findByKey('nope');
    }

    public function test_repeated_misses_do_not_query_every_time(): void
    {
        $this->makeApp('a');
        $provider = new DatabaseApplicationProvider(ttl: 60);
        $provider->all();

        $queries = $this->countQueries(function () use ($provider) {
            foreach (range(1, 20) as $i) {
                try {
                    $provider->findByKey('bad-'.$i);
                } catch (InvalidApplication) {
                }
            }
        });

        $this->assertSame(0, $queries);
    }

    public function test_zero_ttl_always_reloads(): void
    {
        $provider = new DatabaseApplicationProvider(ttl: 0);
        $this->makeApp('a');
        $provider->findByKey('key-a');

        $this->makeApp('b');

        $this->assertSame('b', $provider->findByKey('key-b')->id());
    }
}
