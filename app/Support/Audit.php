<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Audit events that are not plain model changes: sign-ins, viewing or
 * regenerating credentials, role and permission changes, and so on. Model
 * creates, updates, and deletes are logged by the LogsActivity trait.
 *
 * Every entry, from here or from the trait, gets request context attached
 * by {@see self::addContext()}.
 */
class Audit
{
    /** Set while an artisan command runs, so its entries say "cli". */
    public static bool $runningCommand = false;

    /**
     * Run a command's work with its audit entries marked as command line.
     * `php artisan` also sets this through Laravel's command events; this
     * covers commands invoked any other way (Artisan::call, tests).
     */
    public static function fromCommandLine(callable $callback): mixed
    {
        $previous = self::$runningCommand;
        self::$runningCommand = true;

        try {
            return $callback();
        } finally {
            self::$runningCommand = $previous;
        }
    }

    public static function log(string $event, string $description, ?Model $subject = null, array $properties = []): void
    {
        $logger = activity()->event($event)->withProperties($properties);

        if ($subject) {
            $logger->performedOn($subject);
        }

        $logger->log($description);
    }

    /**
     * Log a change to a set of names (a user's roles, a role's permissions)
     * as what was added and removed. Nothing is logged if nothing changed.
     *
     * @param  iterable<string>  $before
     * @param  iterable<string>  $after
     */
    public static function setChanged(string $event, string $description, Model $subject, iterable $before, iterable $after): void
    {
        $before = collect($before)->values();
        $after = collect($after)->values();
        $added = $after->diff($before)->values()->all();
        $removed = $before->diff($after)->values()->all();

        if ($added || $removed) {
            self::log($event, $description, $subject, array_filter(['added' => $added, 'removed' => $removed]));
        }
    }

    /**
     * Where an entry came from: the web dashboard, the API, or the command
     * line, plus the client's IP and browser for requests.
     */
    public static function addContext(Activity $activity): void
    {
        $context = self::$runningCommand
            ? ['source' => 'cli']
            : [
                'source' => request()->is('api/*') ? 'api' : 'web',
                'ip' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
            ];

        $activity->properties = collect($activity->properties)->merge($context);
    }
}
