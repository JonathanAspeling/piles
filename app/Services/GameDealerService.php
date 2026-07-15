<?php

namespace App\Services;

use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;

class GameDealerService
{
    /** @var array<int, int> */
    private const SETS_PER_PLAYER_COUNT = [
        2 => 13,
        3 => 19,
        4 => 25,
        5 => 31,
        6 => 37,
        7 => 43,
    ];

    public function deal(GameSession $game): void
    {
        $players = $game->gamePlayers()->get();
        $playerCount = $players->count();
        $setsCount = self::SETS_PER_PLAYER_COUNT[$playerCount];
        $centerCardCount = $game->centerCardCount();

        if ($game->variant) {
            $setsCount += 1;
        }

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

    public static function setsForPlayerCount(int $playerCount): int
    {
        return self::SETS_PER_PLAYER_COUNT[$playerCount] ?? throw new \InvalidArgumentException("Unsupported player count: {$playerCount}");
    }
}
