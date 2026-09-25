<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reverb_apps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('app_id')->unique();
            $table->string('key')->unique();
            $table->text('secret');
            $table->json('allowed_origins');
            $table->unsignedInteger('ping_interval')->default(60);
            $table->unsignedInteger('activity_timeout')->default(30);
            $table->unsignedInteger('max_message_size')->default(10000);
            $table->unsignedInteger('max_connections')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reverb_apps');
    }
};
