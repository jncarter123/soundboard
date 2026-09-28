<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A group of users who share access to a set of apps, and where alerts
 * about those apps go. Membership changes go through a pivot table, which
 * fires no model events, so they are logged as their own events.
 */
class Team extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'alert_mail_to', 'alert_webhook_url', 'alert_webhook_secret'];

    protected $hidden = ['alert_webhook_secret'];

    protected function casts(): array
    {
        return [
            'alert_mail_to' => 'array',
            'alert_webhook_secret' => 'encrypted',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function apps(): HasMany
    {
        return $this->hasMany(ReverbApp::class);
    }

    /**
     * A new signing secret for the team's webhook, like app secrets.
     */
    public static function generateWebhookSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Name and alert destination changes are audited; the webhook secret
     * never is. Viewing or regenerating it is logged as its own event.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'alert_mail_to', 'alert_webhook_url'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => ucfirst($event).' team');
    }
}
