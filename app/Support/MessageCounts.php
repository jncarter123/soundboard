<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Messages each app has sent and received so far today (UTC), from the
 * counts Reverb's Pulse recorder stores.
 *
 * "Sent" is every frame Reverb delivers to a client, so one broadcast to
 * 1,000 subscribers counts 1,000; "received" is every frame from a client.
 * Each Reverb server records its own, so the sum covers all of them. Reverb
 * writes them to the database every pulse_ingest_interval seconds (15).
 */
class MessageCounts
{
    /**
     * Pulse's 24-hour period, whose buckets are 1,440 seconds long. A day is
     * exactly 60 of them, so midnight UTC always starts a bucket.
     */
    private const PERIOD = 1440;

    /**
     * @return array<string, array{sent: int, received: int, total: int}> keyed by app_id
     */
    public static function today(): array
    {
        $rows = DB::connection(config('pulse.storage.database.connection'))
            ->table('pulse_aggregates')
            ->select('key', 'type', DB::raw('sum(value) as total'))
            ->whereIn('type', ['reverb_message:sent', 'reverb_message:received'])
            ->where('aggregate', 'count')
            ->where('period', self::PERIOD)
            ->where('bucket', '>=', CarbonImmutable::now('UTC')->startOfDay()->getTimestamp())
            ->groupBy('key', 'type')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->key] ??= ['sent' => 0, 'received' => 0, 'total' => 0];
            $counts[$row->key][$row->type === 'reverb_message:sent' ? 'sent' : 'received'] = (int) $row->total;
            $counts[$row->key]['total'] += (int) $row->total;
        }

        return $counts;
    }
}
