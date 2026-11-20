<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The variant (an extra set and 8 centre cards) is replaced by the "similar
     * items" deck. The column is renamed, but the old values meant something
     * else, so every game starts with similar items off.
     */
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->renameColumn('variant', 'similar_items');
        });

        DB::table('game_sessions')->update(['similar_items' => false]);
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->renameColumn('similar_items', 'variant');
        });

        DB::table('game_sessions')->update(['variant' => false]);
    }
};
