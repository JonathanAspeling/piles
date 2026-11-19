<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            // Seeds the per-game shuffle of hard colour variant backgrounds.
            // New games get a random value from GameSession::booted().
            $table->unsignedInteger('background_seed')->default(0)->after('hard_variant');
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn('background_seed');
        });
    }
};
