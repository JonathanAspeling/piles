<?php

namespace App\Services;

use App\Models\GamePlayer;
use App\Models\PileCard;

class GameVerifierService
{
    /**
     * Verify that all 6 of the claimant's piles are valid matching sets.
     *
     * A valid set has 4 cards sharing the same clothing_type with all 4 distinct colours (0–3).
     */
    public function verify(GamePlayer $claimant): bool
    {
        $piles = $claimant->piles()->with('pileCards.card')->get();

        if ($piles->count() !== 6) {
            return false;
        }

        foreach ($piles as $pile) {
            $cards = $pile->pileCards->map(fn (PileCard $pc) => $pc->card);

            if ($cards->count() !== 4) {
                return false;
            }

            $uniqueClothingTypes = $cards->pluck('clothing_type')->unique();

            if ($uniqueClothingTypes->count() !== 1) {
                return false;
            }

            $sortedColors = $cards->pluck('color')
                ->map(fn ($color) => is_int($color) ? $color : $color->value)
                ->sort()
                ->values()
                ->toArray();

            if ($sortedColors !== [0, 1, 2, 3]) {
                return false;
            }
        }

        return true;
    }
}
