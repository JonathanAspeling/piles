<?php

namespace App\Services;

use App\Exceptions\StaleVersionException;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CardSwapService
{
    /**
     * Atomically swap one card from a player's held pile with a centre pile card.
     *
     * @throws StaleVersionException when another player concurrently modified the centre pile
     * @throws ModelNotFoundException
     */
    public function swap(
        GameSession $game,
        GamePlayer $player,
        int $centerPileId,
        int $centerCardId,
        int $myCardId,
        int $expectedCenterVersion,
    ): void {
        DB::transaction(function () use ($game, $player, $centerPileId, $centerCardId, $myCardId, $expectedCenterVersion) {
            $centerPile = Pile::where('id', $centerPileId)
                ->where('game_session_id', $game->id)
                ->whereNull('game_player_id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($centerPile->version !== $expectedCenterVersion) {
                throw new StaleVersionException;
            }

            // Verify the card is actually in the centre pile
            $centerPileCard = PileCard::where('pile_id', $centerPile->id)
                ->where('card_id', $centerCardId)
                ->firstOrFail();

            // Verify the player's card is in one of their piles belonging to this game
            $playerPileCard = PileCard::whereHas('pile', function ($q) use ($game, $player) {
                $q->where('game_session_id', $game->id)
                    ->where('game_player_id', $player->id)
                    ->where('is_completed', false);
            })
                ->where('card_id', $myCardId)
                ->firstOrFail();

            // Move player's card to centre pile, centre card to player's pile
            $centerPileCard->update(['card_id' => $myCardId]);
            $playerPileCard->update(['card_id' => $centerCardId]);

            $centerPile->increment('version');
        });
    }
}
