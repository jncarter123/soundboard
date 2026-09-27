<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A soft daily message limit per app. Reverb has no such setting, so it only
 * drives Soundboard's display and alerts; null means no limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reverb_apps', function (Blueprint $table) {
            $table->unsignedBigInteger('max_messages_per_day')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reverb_apps', function (Blueprint $table) {
            $table->dropColumn('max_messages_per_day');
        });
    }
};
