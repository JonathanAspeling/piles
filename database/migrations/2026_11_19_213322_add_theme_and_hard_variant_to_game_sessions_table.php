<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            // A plain string (not a DB enum) so new themes only need a new CardTheme case.
            $table->string('theme')->default('standard')->after('variant');
            $table->boolean('hard_variant')->default(false)->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn(['theme', 'hard_variant']);
        });
    }
};
