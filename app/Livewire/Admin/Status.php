<?php

namespace App\Livewire\Admin;

use App\Models\ReverbApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Livewire\Component;
use Throwable;

class Status extends Component
{
    public array $checks = [];

    public array $apps = [];

    public function mount(): void
    {
        $this->runChecks();
        $this->loadApps();
    }

    public function refresh(): void
    {
        $this->runChecks();
        $this->loadApps();
    }

    private function runChecks(): void
    {
        $this->checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];
    }

    private function loadApps(): void
    {
        $this->apps = ReverbApp::orderBy('name')
            ->get(['app_id', 'name', 'allowed_origins'])
            ->map(fn (ReverbApp $app) => [
                'app_id' => $app->app_id,
                'name' => $app->name,
                'allowed_origins' => $app->allowed_origins,
            ])
            ->all();
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');
            $config = DB::connection()->getConfig();

            return [
                'up' => true,
                'detail' => $config['driver'].'://'.($config['host'] ?? 'localhost').'/'.$config['database'],
            ];
        } catch (Throwable $e) {
            return ['up' => false, 'detail' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        if (! config('reverb.servers.reverb.scaling.enabled')) {
            return ['up' => null, 'detail' => 'Scaling disabled — Redis not in use'];
        }

        try {
            Redis::connection()->ping();

            return ['up' => true, 'detail' => 'Connected'];
        } catch (Throwable $e) {
            return ['up' => false, 'detail' => $e->getMessage()];
        }
    }

    public function render()
    {
        return view('livewire.admin.status')
            ->layout('components.layouts.app');
    }
}
