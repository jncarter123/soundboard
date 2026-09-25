<?php

use Illuminate\Support\Facades\Schedule;

// Remove audit entries older than ACTIVITYLOG_CLEAN_AFTER_DAYS (365 by
// default). Runs from the `scheduler` container with Docker, or from the
// `schedule:run` cron line otherwise; see the README.
Schedule::command('activitylog:clean --force')->daily()->onOneServer();

// Alert checks: connection limits, Reverb reachability, stale metrics.
Schedule::command('soundboard:check-alerts')->everyMinute()->withoutOverlapping()->onOneServer();
