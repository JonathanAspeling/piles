<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat_index');
            $table->boolean('is_ready')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique(['game_session_id', 'user_id']);
            $table->unique(['game_session_id', 'seat_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
