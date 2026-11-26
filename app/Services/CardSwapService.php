<?php

namespace App\Services;

use App\Events\CenterPileSwapped;
use App\Events\PlayerPileCompleted;
use App\Exceptions\StaleVersionException;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CardSwapService
{
    /**
     * A whole move: swap one of the player's cards for a centre card, tell the
     * table, and lay the pile face-up if that swap completed it. People (via
     * the swap endpoint) and bots make moves through here alike.
     *
     * @throws StaleVersionException when another player concurrently modified the centre pile
     * @throws ModelNotFoundException
     */
    public function move(
        GameSession $game,
        GamePlayer $player,
        int $centerPileId,
        int $centerCardId,
        int $myCardId,
        int $myPileId,
        int $expectedCenterVersion,
    ): void {
        $centerPile = $this->swap($game, $player, $centerPileId, $centerCardId, $myCardId, $expectedCenterVersion);

        $player->update(['picked_up_card_id' => null, 'picked_up_pile_id' => null]);

        broadcast(new CenterPileSwapped($game, $centerPile, Card::findOrFail($myCardId)));

        $playerPile = Pile::where('id', $myPileId)
            ->where('game_session_id', $game->id)
            ->where('game_player_id', $player->id)
            ->with('pileCards.card')
            ->firstOrFail();

        $this->layDownIfComplete($game, $player, $playerPile);
    }

    /**
     * Lays a pile face-up once its four cards are all one item, and tells the
     * table. Returns whether it did.
     */
    public function layDownIfComplete(GameSession $game, GamePlayer $player, Pile $pile): bool
    {
        $pile->loadMissing('pileCards.card');
        $clothingTypes = $pile->pileCards->map(fn ($pileCard) => $pileCard->card->clothing_type->value)->unique();

        if ($pile->is_completed || $pile->pileCards->count() !== 4 || $clothingTypes->count() !== 1) {
            return false;
        }

        $pile->update(['is_completed' => true]);
        broadcast(new PlayerPileCompleted($game, $player, $pile));

        return true;
    }

    /**
     * Atomically swap one card from a player's held pile with a centre pile card.
     *
     * Returns the centre pile as committed, its version bumped.
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
    ): Pile {
        return DB::transaction(function () use ($game, $player, $centerPileId, $centerCardId, $myCardId, $expectedCenterVersion) {
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

            return $centerPile;
        });
    }
}
