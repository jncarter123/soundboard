<?php

namespace App\Livewire\Admin;

use App\Alerts\AlertConditions;
use App\Models\Alert;
use App\Models\ReverbApp;
use App\Services\ReverbApiService;
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
            'clock' => $this->checkClock(),
        ];
    }

    /**
     * Reverb's clock against ours. Beyond 10 minutes Reverb rejects
     * Soundboard's signed requests.
     */
    private function checkClock(): array
    {
        $skew = app(ReverbApiService::class)->getClockSkew();

        if ($skew === null) {
            return ['level' => 'unknown', 'detail' => 'Reverb not reachable'];
        }

        $drift = abs($skew) < 1
            ? 'In sync'
            : AlertConditions::describeSkew($skew, short: true).($skew > 0 ? ' ahead' : ' behind');

        return [
            'level' => match (true) {
                abs($skew) > AlertConditions::REVERB_SIGNATURE_TOLERANCE => 'critical',
                abs($skew) >= (int) config('alerts.clock_skew_warning_seconds', 300) => 'warning',
                default => 'ok',
            },
            'detail' => $drift,
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
        return view('livewire.admin.status', [
            'activeAlerts' => Alert::active()->orderByRaw("severity = 'critical' desc")->latest('triggered_at')->get(),
            'recentAlerts' => Alert::whereNotNull('resolved_at')->latest('resolved_at')->limit(5)->get(),
            'alertDestinations' => array_filter([
                config('alerts.mail_to') ? 'email' : null,
                config('alerts.webhook_url') ? 'webhook' : null,
            ]),
        ])
            ->layout('components.layouts.app');
    }
}
