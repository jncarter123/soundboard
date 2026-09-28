<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The team an entry belongs to, recorded when it's written, so team owners
 * can read their team's history even after an app is deleted. No foreign
 * key: entries outlive their team. Existing entries are backfilled from
 * the apps and teams that still exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->index();
        });

        // Correlated subqueries, which SQLite, MySQL, and MariaDB all accept.
        DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\ReverbApp')
            ->update(['team_id' => DB::raw('(select team_id from reverb_apps where reverb_apps.id = activity_log.subject_id)')]);

        DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\Team')
            ->whereIn('subject_id', DB::table('teams')->select('id'))
            ->update(['team_id' => DB::raw('subject_id')]);
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
