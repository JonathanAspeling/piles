<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('clothing_type');
            $table->unsignedTinyInteger('color');
            $table->timestamps();

            $table->unique(['clothing_type', 'color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
