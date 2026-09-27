<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A group of users who share access to a set of apps. Membership changes
 * go through a pivot table, which fires no model events, so they are
 * logged as their own events.
 */
class Team extends Model
{
    use LogsActivity;

    protected $fillable = ['name'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function apps(): HasMany
    {
        return $this->hasMany(ReverbApp::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => ucfirst($event).' team');
    }
}
