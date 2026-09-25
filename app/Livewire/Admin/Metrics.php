<?php

namespace App\Livewire\Admin;

use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Attributes\Url;
use Livewire\Component;

class Metrics extends Component
{
    public string $mode = 'live';

    public string $period = '1_hour';

    public array $liveData = [];

    public array $historicalData = [];

    #[Url(as: 'app', keep: false)]
    public ?string $selectedApp = null;

    public array $detailData = [];

    public ?string $lastUpdated = null;

    protected array $periods = [
        '1_hour' => '1 Hour',
        '6_hours' => '6 Hours',
        '24_hours' => '24 Hours',
        '7_days' => '7 Days',
    ];

    public function mount(): void
    {
        // A deep link (?app=...) lands directly on the historical detail view.
        if ($this->selectedApp !== null) {
            $this->mode = 'historical';
            $this->loadHistoricalData();
            $this->loadAppDetail();

            return;
        }

        $this->loadLiveData();
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;

        if ($mode === 'live') {
            $this->clearSelection();
            $this->loadLiveData();
        } else {
            $this->loadHistoricalData();
        }
    }

    public function setPeriod(string $period): void
    {
        $this->period = $period;
        $this->loadHistoricalData();

        if ($this->selectedApp !== null) {
            $this->loadAppDetail();
        }
    }

    public function selectApp(string $appId): void
    {
        $this->selectedApp = $appId;
        $this->loadAppDetail();
    }

    public function clearSelection(): void
    {
        $this->selectedApp = null;
        $this->detailData = [];
    }

    public function refreshLive(): void
    {
        $this->loadLiveData();
    }

    public function refreshHistorical(): void
    {
        $this->loadHistoricalData();

        if ($this->selectedApp !== null) {
            $this->loadAppDetail();
        }
    }

    protected function loadLiveData(): void
    {
        $this->liveData = app(ReverbApiService::class)->getAllAppsLiveStats();
        $this->lastUpdated = now()->format('g:i:s A');
    }

    protected function loadHistoricalData(): void
    {
        $interval = $this->periodToInterval();

        $connections = Pulse::aggregate(
            'reverb_connections',
            ['avg', 'max'],
            $interval,
        );

        $period = $interval->totalSeconds / 60;
        $oldestBucket = (int) (floor(CarbonImmutable::now()->getTimestamp() / $period) * $period) - $interval->totalSeconds + $period;

        $messageCounts = DB::connection(config('pulse.storage.database.connection'))
            ->table('pulse_aggregates')
            ->select('key', 'type', DB::raw('sum(value) as total'))
            ->whereIn('type', ['reverb_message:sent', 'reverb_message:received'])
            ->where('aggregate', 'count')
            ->where('period', $period)
            ->where('bucket', '>=', $oldestBucket)
            ->groupBy('key', 'type')
            ->get();

        $apps = ReverbApp::all();

        $this->historicalData = $apps->map(function ($app) use ($connections, $messageCounts) {
            $appConnections = $connections->firstWhere('key', $app->app_id);
            $sent = $messageCounts->where('key', $app->app_id)->where('type', 'reverb_message:sent')->first();
            $received = $messageCounts->where('key', $app->app_id)->where('type', 'reverb_message:received')->first();

            return [
                'app_id' => $app->app_id,
                'name' => $app->name,
                'avg_connections' => $appConnections ? round($appConnections->avg, 1) : 0,
                'max_connections' => $appConnections ? (int) $appConnections->max : 0,
                'messages_sent' => $sent ? (int) $sent->total : 0,
                'messages_received' => $received ? (int) $received->total : 0,
            ];
        })->all();

        $this->lastUpdated = now()->format('g:i:s A');
    }

    protected function loadAppDetail(): void
    {
        $app = ReverbApp::where('app_id', $this->selectedApp)->first();

        if ($app === null) {
            $this->clearSelection();

            return;
        }

        $interval = $this->periodToInterval();

        // Pulse::graph() returns 60 buckets across the interval, keyed by
        // app_id => type => { "Y-m-d H:i:s" => value|null } with null padding.
        $connAvg = Pulse::graph(['reverb_connections'], 'avg', $interval);
        $connMax = Pulse::graph(['reverb_connections'], 'max', $interval);
        $messages = Pulse::graph(['reverb_message:sent', 'reverb_message:received'], 'count', $interval);

        $avgSeries = $this->extractSeries($connAvg, $app->app_id, 'reverb_connections');
        $maxSeries = $this->extractSeries($connMax, $app->app_id, 'reverb_connections');
        $sentSeries = $this->extractSeries($messages, $app->app_id, 'reverb_message:sent');
        $receivedSeries = $this->extractSeries($messages, $app->app_id, 'reverb_message:received');

        $labels = array_map(fn ($bucket) => $this->formatBucketLabel($bucket), array_keys($avgSeries));

        $this->detailData = [
            'app_id' => $app->app_id,
            'name' => $app->name,
            'labels' => $labels,
            'connections' => [
                'avg' => array_values($avgSeries),
                'max' => array_values($maxSeries),
                'peak' => (int) (max([0, ...array_map(fn ($v) => (int) $v, $maxSeries)])),
            ],
            'messages' => [
                'sent' => array_map(fn ($v) => (int) $v, array_values($sentSeries)),
                'received' => array_map(fn ($v) => (int) $v, array_values($receivedSeries)),
                'sent_total' => (int) array_sum($sentSeries),
                'received_total' => (int) array_sum($receivedSeries),
            ],
        ];

        $this->lastUpdated = now()->format('g:i:s A');
    }

    /**
     * Pull an ordered bucket => value map for one app/type out of a Pulse graph result.
     *
     * @return array<string, float|int|null>
     */
    protected function extractSeries(mixed $graph, string $appId, string $type): array
    {
        $series = $graph->get($appId)?->get($type);

        return $series ? $series->all() : [];
    }

    protected function formatBucketLabel(string $bucket): string
    {
        $time = CarbonImmutable::parse($bucket);

        return match ($this->period) {
            '7_days' => $time->format('M j g A'),
            '24_hours' => $time->format('g:i A'),
            default => $time->format('g:i A'),
        };
    }

    protected function periodToInterval(): CarbonInterval
    {
        return match ($this->period) {
            '1_hour' => CarbonInterval::hour(),
            '6_hours' => CarbonInterval::hours(6),
            '24_hours' => CarbonInterval::hours(24),
            '7_days' => CarbonInterval::days(7),
            default => CarbonInterval::hour(),
        };
    }

    public function render()
    {
        return view('livewire.admin.metrics', [
            'periods' => $this->periods,
        ])->layout('components.layouts.app');
    }
}
