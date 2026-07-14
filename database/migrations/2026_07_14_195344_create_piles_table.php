<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_player_id')->nullable()->constrained('game_players')->cascadeOnDelete();
            $table->unsignedTinyInteger('pile_index');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piles');
    }
};
