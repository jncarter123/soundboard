<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    protected $fillable = [
        'key', 'type', 'severity', 'app_id', 'message', 'details',
        'triggered_at', 'resolved_at', 'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isCritical(): bool
    {
        return $this->severity === self::CRITICAL;
    }
}
