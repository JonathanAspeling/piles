<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            // The lobby an ended game's players regroup in for "Play again".
            // Nulled if that lobby empties and is deleted, so the next
            // "Play again" opens a fresh one.
            $table->foreignId('rematch_game_session_id')
                ->nullable()
                ->after('winner_user_id')
                ->constrained('game_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rematch_game_session_id');
        });
    }
};
