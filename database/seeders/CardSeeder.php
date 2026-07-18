<?php

namespace Database\Seeders;

use App\Models\Card;
use Illuminate\Database\Seeder;

class CardSeeder extends Seeder
{
    public function run(): void
    {
        // 47 clothing types × 4 colours = 188 cards, seeded once
        for ($clothingType = 0; $clothingType <= 46; $clothingType++) {
            for ($color = 0; $color <= 3; $color++) {
                Card::firstOrCreate([
                    'clothing_type' => $clothingType,
                    'color' => $color,
                ]);
            }
        }
    }
}
