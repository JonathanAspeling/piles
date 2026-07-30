<?php

namespace App\Services;

use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;

class GameDealerService
{
    private const CARDS_PER_PILE = 4;

    private const PILES_PER_PLAYER = 6;

    public function deal(GameSession $game): void
    {
        $players = $game->gamePlayers()->get();
        $playerCount = $players->count();
        $centerCardCount = $game->centerCardCount($playerCount);
        $setsCount = self::setsForGame($playerCount, $centerCardCount);

        // Pick $setsCount random clothing types, then fetch all 4 cards per type
        $clothingTypes = Card::select('clothing_type')
            ->distinct()
            ->inRandomOrder()
            ->limit($setsCount)
            ->pluck('clothing_type');

        $cards = Card::whereIn('clothing_type', $clothingTypes)
            ->get()
            ->shuffle();

        // Create center piles — each holds exactly 1 card
        for ($i = 0; $i < $centerCardCount; $i++) {
            $pile = Pile::create([
                'game_session_id' => $game->id,
                'game_player_id' => null,
                'pile_index' => $i,
            ]);

            PileCard::create([
                'pile_id' => $pile->id,
                'card_id' => $cards->shift()->id,
                'position' => 0,
            ]);
        }

        // Deal 6 piles of 4 cards to each player
        foreach ($players as $player) {
            for ($pileIndex = 0; $pileIndex < 6; $pileIndex++) {
                $pile = Pile::create([
                    'game_session_id' => $game->id,
                    'game_player_id' => $player->id,
                    'pile_index' => $pileIndex,
                ]);

                $pileCards = $cards->splice(0, 4);

                foreach ($pileCards as $position => $card) {
                    PileCard::create([
                        'pile_id' => $pile->id,
                        'card_id' => $card->id,
                        'position' => $position,
                    ]);
                }
            }
        }

        $game->update([
            'sets_count' => $setsCount,
            'started_at' => now(),
        ]);
    }

    private static function setsForGame(int $playerCount, int $centerCardCount): int
    {
        if ($playerCount < 2 || $playerCount > 10) {
            throw new \InvalidArgumentException("Unsupported player count: {$playerCount}");
        }

        $cardsNeeded = $playerCount * self::PILES_PER_PLAYER * self::CARDS_PER_PILE + $centerCardCount;

        return intdiv($cardsNeeded, self::CARDS_PER_PILE);
    }
}
