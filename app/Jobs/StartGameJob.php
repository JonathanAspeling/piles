<?php

namespace App\Jobs;

use App\Enums\GameStatus;
use App\Events\GameActivated;
use App\Models\GameSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StartGameJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly GameSession $game) {}

    public function handle(): void
    {
        $game = $this->game->fresh();

        if ($game->status !== GameStatus::Countdown) {
            return;
        }

        // Play time is measured from GO, not from the deal: loading and the
        // countdown aren't part of how long the game took.
        $game->update(['status' => GameStatus::Playing, 'started_at' => now()]);

        broadcast(new GameActivated($game));
    }
}
