<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameDuplicateNames();

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }

    /**
     * The name only becomes an identity now, so existing rows may share one.
     * Suffix every duplicate after the oldest to clear the way for the index.
     */
    private function renameDuplicateNames(): void
    {
        $duplicated = DB::table('users')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        foreach ($duplicated as $name) {
            $ids = DB::table('users')->where('name', $name)->orderBy('id')->pluck('id')->skip(1);

            foreach ($ids as $id) {
                DB::table('users')->where('id', $id)->update(['name' => $this->availableName($name)]);
            }
        }
    }

    private function availableName(string $name): string
    {
        $suffix = 2;

        while (DB::table('users')->where('name', $name.' ('.$suffix.')')->exists()) {
            $suffix++;
        }

        return $name.' ('.$suffix.')';
    }
};
