<?php

namespace App\Services;

use App\Enums\ClaimOutcome;
use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\GameResumed;
use App\Models\GamePlayer;
use App\Models\GameSession;

/**
 * Settles a "PILES!" call, from a person or a bot: only the first of several
 * simultaneous calls is verified, and the game either ends with that caller
 * as the winner or resumes.
 */
class PilesClaimService
{
    public function __construct(private GameVerifierService $verifier) {}

    public function claim(GameSession $game, GamePlayer $player): ClaimOutcome
    {
        // Atomic transition — only the first simultaneous claim proceeds
        $transitioned = GameSession::where('id', $game->id)
            ->where('status', GameStatus::Playing)
            ->update(['status' => GameStatus::Verifying]);

        if (! $transitioned) {
            return ClaimOutcome::AlreadyVerifying;
        }

        // Resync so subsequent $game->update() calls treat Verifying as the
        // baseline. Without this, Eloquent's dirty check treats a Verifying→Playing
        // reset as no-op (original attribute was still Playing) and silently
        // skips the SQL UPDATE, leaving the game stuck in Verifying.
        $game->refresh();

        if ($this->verifier->verify($player)) {
            $game->update([
                'status' => GameStatus::Ended,
                'winner_user_id' => $player->user_id,
                'ended_at' => now(),
            ]);

            broadcast(new GameEnded($game, $player->loadMissing('user')));

            return ClaimOutcome::Won;
        }

        $game->update(['status' => GameStatus::Playing]);

        broadcast(new GameResumed($game, $player->loadMissing('user')->user->name));

        return ClaimOutcome::Rejected;
    }
}
