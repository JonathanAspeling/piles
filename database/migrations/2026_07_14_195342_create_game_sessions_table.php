<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('status')->default('lobby');
            $table->foreignId('host_user_id')->constrained('users');
            $table->boolean('variant')->default(false);
            $table->unsignedTinyInteger('sets_count')->nullable();
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
