<?php

namespace App\Services;

use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use Illuminate\Support\Facades\DB;

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
}
