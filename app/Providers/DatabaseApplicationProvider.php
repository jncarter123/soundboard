<?php

namespace App\Providers;

use App\Models\ReverbApp;
use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;

/**
 * Serves Reverb apps from the database.
 *
 * Reverb resolves the app on every WebSocket connection and API request, and
 * the lookup blocks its event loop. Apps are therefore held in memory and
 * reloaded at most once per `reverb.apps.cache_ttl` seconds, so edits in the
 * dashboard reach a running server within that window. An unknown id/key
 * forces an early reload (rate-limited) so newly created apps work right away.
 */
class DatabaseApplicationProvider implements ApplicationProvider
{
    /**
     * Minimum seconds between reloads triggered by a lookup miss, so a client
     * hammering bad keys can't turn every attempt into a query.
     */
    private const MISS_RELOAD_INTERVAL = 1.0;

    /** @var Collection<int, Application>|null */
    private ?Collection $apps = null;

    private float $loadedAt = 0.0;

    public function __construct(
        private readonly int $ttl = 10,
    ) {}

    public function all(): Collection
    {
        return $this->apps();
    }

    public function findById(string $id): Application
    {
        return $this->find(fn (Application $app) => $app->id() === $id);
    }

    public function findByKey(string $key): Application
    {
        return $this->find(fn (Application $app) => $app->key() === $key);
    }

    private function find(callable $matches): Application
    {
        $app = $this->apps()->first($matches);

        if (! $app && $this->age() >= self::MISS_RELOAD_INTERVAL) {
            $app = $this->reload()->first($matches);
        }

        return $app ?? throw new InvalidApplication;
    }

    /**
     * @return Collection<int, Application>
     */
    private function apps(): Collection
    {
        if ($this->apps === null || $this->age() >= $this->ttl) {
            return $this->reload();
        }

        return $this->apps;
    }

    /**
     * @return Collection<int, Application>
     */
    private function reload(): Collection
    {
        $this->loadedAt = microtime(true);

        return $this->apps = ReverbApp::all()
            ->map(fn (ReverbApp $app) => $app->toReverbApplication())
            ->values();
    }

    private function age(): float
    {
        return microtime(true) - $this->loadedAt;
    }
}
