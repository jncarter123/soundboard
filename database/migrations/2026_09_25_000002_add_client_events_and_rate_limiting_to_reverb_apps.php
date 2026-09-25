<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two per-app Reverb options that the config provider supports but the
 * database provider never passed through. Defaults match what every app has
 * effectively had until now: client events from channel members only, and no
 * rate limiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reverb_apps', function (Blueprint $table) {
            // all | members | none
            $table->string('accept_client_events_from', 16)->default('members');
            $table->boolean('rate_limit_enabled')->default(false);
            $table->unsignedInteger('rate_limit_max_attempts')->default(60);
            $table->unsignedInteger('rate_limit_decay_seconds')->default(60);
            $table->boolean('rate_limit_terminate')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('reverb_apps', function (Blueprint $table) {
            $table->dropColumn([
                'accept_client_events_from',
                'rate_limit_enabled',
                'rate_limit_max_attempts',
                'rate_limit_decay_seconds',
                'rate_limit_terminate',
            ]);
        });
    }
};
