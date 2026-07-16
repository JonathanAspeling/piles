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

        $game->update(['status' => GameStatus::Playing]);

        broadcast(new GameActivated($game));
    }
}
