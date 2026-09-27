<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // An App\Enums\TeamRole value.
            $table->string('role');
            $table->timestamps();

            $table->primary(['team_id', 'user_id']);
        });

        // Null for apps that belong to no team, which only users with the
        // global apps permissions can see.
        Schema::table('reverb_apps', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reverb_apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
