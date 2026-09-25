<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            // Identifies the condition, e.g. "connections:storefront". One
            // active (unresolved) alert per key at a time.
            $table->string('key')->index();
            $table->string('type');
            $table->string('severity');
            $table->string('app_id')->nullable();
            $table->string('message');
            $table->json('details')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
