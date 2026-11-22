<?php

namespace App\Services;

use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PileReorderService
{
    /**
     * Rewrite a player's pile_index values to match the order they submitted.
     *
     * The payload is the player's complete intended order rather than a pair to
     * swap, which makes a retry idempotent and lets a client that has drifted
     * correct itself in one request.
     *
     * @param  array<int, int>  $orderedPileIds
     */
    public function reorder(GameSession $game, GamePlayer $player, array $orderedPileIds): void
    {
        DB::transaction(function () use ($game, $player, $orderedPileIds) {
            // SQLite ignores SELECT ... FOR UPDATE, and unlike a card swap there is
            // no version column to fall back on, so claim the game row by writing
            // to it. A double-tap or a retried request then queues instead of
            // interleaving two partial renumberings.
            GameSession::whereKey($game->getKey())->update(['updated_at' => now()]);

            $piles = Pile::where('game_session_id', $game->id)
                ->where('game_player_id', $player->id)
                ->get()
                ->keyBy('id');

            // Must be a permutation of exactly this player's piles: no foreign
            // piles, none missing, none invented. Deliberately count-agnostic so
            // it doesn't restate how many piles a hand holds.
            abort_unless(
                count($orderedPileIds) === $piles->count()
                    && $piles->keys()->diff($orderedPileIds)->isEmpty(),
                422,
                'That is not your current set of piles.',
            );

            foreach ($orderedPileIds as $position => $pileId) {
                $piles[$pileId]->update(['pile_index' => $position]);
            }
        });
    }

    /**
     * Rewrite the order of the cards within one of a player's piles.
     *
     * Like pile order it's presentational, but persisted so a refresh or a
     * resync keeps it. The ids must be exactly the cards in the pile now: a
     * swap that landed first changes them, which is a conflict (409) the
     * client answers by resyncing, not a malformed request.
     *
     * @param  array<int, int>  $orderedCardIds
     */
    public function reorderCards(GameSession $game, GamePlayer $player, int $pileId, array $orderedCardIds): void
    {
        DB::transaction(function () use ($game, $player, $pileId, $orderedCardIds) {
            $pile = Pile::where('id', $pileId)
                ->where('game_session_id', $game->id)
                ->where('game_player_id', $player->id)
                ->firstOrFail();

            abort_if($pile->is_completed, 422, 'A completed pile cannot be rearranged.');

            $pileCards = $pile->pileCards()->get()->keyBy('card_id');

            if (count($orderedCardIds) !== $pileCards->count() || $pileCards->keys()->diff($orderedCardIds)->isNotEmpty()) {
                throw new ConflictHttpException('The pile has changed. Refresh and try again.');
            }

            // Positions are unique within a pile, so park every card clear of
            // the final range before writing it.
            foreach ($orderedCardIds as $position => $cardId) {
                PileCard::whereKey($pileCards[$cardId]->id)->update(['position' => $position + 100]);
            }
            foreach ($orderedCardIds as $position => $cardId) {
                PileCard::whereKey($pileCards[$cardId]->id)->update(['position' => $position]);
            }
        });
    }
}
