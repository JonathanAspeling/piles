<?php

namespace App\Jobs;

use App\Enums\ClaimOutcome;
use App\Enums\GameStatus;
use App\Exceptions\StaleVersionException;
use App\Models\GamePlayer;
use App\Services\BotBrain;
use App\Services\CardSwapService;
use App\Services\PilesClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One beat of a bot's play: call PILES! if every pile is done, otherwise make
 * the move BotBrain picks (if any), then queue the next beat after a
 * human-ish pause. The loop ends with the game.
 */
class BotMoveJob implements ShouldQueue
{
    use Queueable;

    /** Pause between a bot's moves, in milliseconds. */
    public const MIN_PAUSE_MS = 1500;

    public const MAX_PAUSE_MS = 3000;

    /** A backstop: no real game needs this many moves, so a loop this long has gone wrong. */
    public const MAX_MOVES = 1500;

    public function __construct(
        public readonly int $gamePlayerId,
        public readonly ?int $lastGivenCardId = null,
        public readonly int $movesMade = 0,
    ) {}

    public function handle(BotBrain $brain, CardSwapService $swaps, PilesClaimService $claims): void
    {
        $bot = GamePlayer::with('gameSession')->find($this->gamePlayerId);
        $game = $bot?->gameSession;

        if (! $game || $game->status === GameStatus::Ended || $this->movesMade >= self::MAX_MOVES) {
            return;
        }

        $lastGivenCardId = $this->lastGivenCardId;

        if ($game->status === GameStatus::Playing) {
            if ($bot->completedPilesCount() === 6) {
                if ($claims->claim($game, $bot) === ClaimOutcome::Won) {
                    return;
                }
            } else {
                $move = $brain->nextMove($game, $bot, $lastGivenCardId);

                if ($move) {
                    try {
                        $swaps->move($game, $bot, $move->centerPileId, $move->centerCardId, $move->myCardId, $move->pileId, $move->centerVersion);
                        $lastGivenCardId = $move->myCardId;
                    } catch (StaleVersionException|ModelNotFoundException) {
                        // Someone got to that centre card first; look again next beat.
                    }
                }
            }
        }

        // Still playing, or someone's call is being verified: carry on.
        self::dispatch($this->gamePlayerId, $lastGivenCardId, $this->movesMade + 1)
            ->delay(now()->addMilliseconds(random_int(self::MIN_PAUSE_MS, self::MAX_PAUSE_MS)));
    }
}
