<?php

namespace App\Services;

use App\Exceptions\NotEnoughCardsException;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameDealerService
{
    private const CARDS_PER_PILE = 4;

    private const PILES_PER_PLAYER = 6;

    /**
     * @throws NotEnoughCardsException when the card table cannot fill every pile
     */
    public function deal(GameSession $game): void
    {
        DB::transaction(function () use ($game) {
            $players = $game->gamePlayers()->orderBy('seat_index')->get();
            $playerCount = $players->count();
            $centerCardCount = $game->centerCardCount($playerCount);
            $cardsNeeded = self::cardsNeeded($playerCount, $centerCardCount);

            $cards = $this->drawDeck($cardsNeeded, $playerCount);

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
                for ($pileIndex = 0; $pileIndex < self::PILES_PER_PLAYER; $pileIndex++) {
                    $pile = Pile::create([
                        'game_session_id' => $game->id,
                        'game_player_id' => $player->id,
                        'pile_index' => $pileIndex,
                    ]);

                    $pileCards = $cards->splice(0, self::CARDS_PER_PILE);

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
                'sets_count' => intdiv($cardsNeeded, self::CARDS_PER_PILE),
                'started_at' => now(),
            ]);
        });
    }

    /**
     * Draw and shuffle enough cards to fill every centre and player pile.
     *
     * Only clothing types holding a complete set of 4 are eligible. The deck is
     * dealt in seat order, so any shortfall — a card table seeded before a set
     * expansion, a half-seeded set — would silently drain into the last seats'
     * piles and hand those players an unplayable game. Fail before dealing.
     *
     * @return Collection<int, Card>
     *
     * @throws NotEnoughCardsException
     */
    private function drawDeck(int $cardsNeeded, int $playerCount): Collection
    {
        $setsNeeded = (int) ceil($cardsNeeded / self::CARDS_PER_PILE);

        $clothingTypes = Card::select('clothing_type')
            ->groupBy('clothing_type')
            ->havingRaw('COUNT(*) = ?', [self::CARDS_PER_PILE])
            ->inRandomOrder()
            ->limit($setsNeeded)
            ->pluck('clothing_type');

        $deck = Card::whereIn('clothing_type', $clothingTypes)->get()->shuffle();

        if ($deck->count() < $cardsNeeded) {
            throw new NotEnoughCardsException(
                "A {$playerCount}-player game needs {$setsNeeded} complete card sets ({$cardsNeeded} cards), "
                ."but only {$clothingTypes->count()} complete sets are seeded."
            );
        }

        return $deck;
    }

    private static function cardsNeeded(int $playerCount, int $centerCardCount): int
    {
        if ($playerCount < 2 || $playerCount > 10) {
            throw new \InvalidArgumentException("Unsupported player count: {$playerCount}");
        }

        return $playerCount * self::PILES_PER_PLAYER * self::CARDS_PER_PILE + $centerCardCount;
    }
}
