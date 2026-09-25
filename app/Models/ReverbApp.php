<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Reverb\Application;

class ReverbApp extends Model
{
    protected $fillable = [
        'name',
        'app_id',
        'key',
        'secret',
        'allowed_origins',
        'ping_interval',
        'activity_timeout',
        'max_message_size',
        'max_connections',
    ];

    protected $attributes = [
        'allowed_origins' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'allowed_origins' => 'array',
            'ping_interval' => 'integer',
            'activity_timeout' => 'integer',
            'max_message_size' => 'integer',
            'max_connections' => 'integer',
        ];
    }

    public function toReverbApplication(): Application
    {
        return new Application(
            id: $this->app_id,
            key: $this->key,
            secret: $this->secret,
            pingInterval: $this->ping_interval,
            activityTimeout: $this->activity_timeout,
            allowedOrigins: $this->allowed_origins,
            maxMessageSize: $this->max_message_size,
            maxConnections: $this->max_connections,
        );
    }

    /**
     * Normalize allowed origins to what Reverb compares against: the bare
     * host of the Origin header (e.g. "app.example.com" or "*.example.com").
     * Full URLs such as "https://app.example.com:443/path" would otherwise
     * never match, so the scheme, port, and path are stripped.
     *
     * @param  iterable<string>  $origins
     * @return list<string>
     */
    public static function normalizeOrigins(iterable $origins): array
    {
        return collect($origins)
            ->map(fn ($origin) => strtolower(trim((string) $origin)))
            ->map(fn (string $origin) => preg_replace(['#^[a-z][a-z0-9+.-]*://#', '#[:/].*$#'], '', $origin))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
