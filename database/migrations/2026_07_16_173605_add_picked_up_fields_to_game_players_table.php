<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->unsignedBigInteger('picked_up_card_id')->nullable()->after('is_game_ready');
            $table->unsignedBigInteger('picked_up_pile_id')->nullable()->after('picked_up_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn(['picked_up_card_id', 'picked_up_pile_id']);
        });
    }
};
