<?php

namespace App\Http\Controllers;

use App\Models\GameSession;

abstract class Controller
{
    /**
     * Claim the game row's write lock and return its freshly committed state.
     *
     * Must be called inside a transaction. Lobby transitions (join, start, leave, seating bots)
     * are otherwise a check-then-act race: a join that reads "lobby" microseconds
     * before the host starts still inserts its player row, and if that row lands
     * after the deal, that player sits in a live game holding no cards. SQLite
     * ignores `SELECT ... FOR UPDATE`, so the row is claimed by writing to it —
     * concurrent transactions then block here, and the read below is guaranteed
     * to see committed state rather than the caller's stale snapshot.
     */
    protected function lockGame(GameSession $game): GameSession
    {
        GameSession::whereKey($game->getKey())->update(['updated_at' => now()]);

        return GameSession::whereKey($game->getKey())->firstOrFail();
    }
}
