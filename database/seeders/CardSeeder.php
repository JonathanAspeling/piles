<?php

namespace Database\Seeders;

use App\Models\Card;
use Illuminate\Database\Seeder;

class CardSeeder extends Seeder
{
    public function run(): void
    {
        // 63 clothing types × 4 colours = 252 cards, seeded once
        for ($clothingType = 0; $clothingType <= 62; $clothingType++) {
            for ($color = 0; $color <= 3; $color++) {
                Card::firstOrCreate([
                    'clothing_type' => $clothingType,
                    'color' => $color,
                ]);
            }
        }
    }
}
