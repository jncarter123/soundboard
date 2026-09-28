<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a team's app alerts go, set by the team itself. The webhook secret
 * is the team's own (encrypted at rest), never the server's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->json('alert_mail_to')->nullable();
            $table->string('alert_webhook_url', 2048)->nullable();
            $table->text('alert_webhook_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['alert_mail_to', 'alert_webhook_url', 'alert_webhook_secret']);
        });
    }
};
