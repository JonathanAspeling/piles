<?php

namespace App\Console\Commands;

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\LobbyUpdated;
use App\Models\GameSession;
use Illuminate\Console\Command;

class EndAbandonedGames extends Command
{
    protected $signature = 'games:end-abandoned {--minutes=15 : Idle threshold in minutes}';

    protected $description = 'End or delete game sessions with no activity beyond the idle threshold.';

    public function handle(): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $lobbyDeleted = GameSession::where('status', GameStatus::Lobby)
            ->where('updated_at', '<', $threshold)
            ->delete();

        $endedCount = 0;

        GameSession::whereIn('status', [
            GameStatus::Countdown,
            GameStatus::Playing,
            GameStatus::Verifying,
        ])
            ->where('updated_at', '<', $threshold)
            ->get()
            ->each(function (GameSession $game) use (&$endedCount) {
                $game->update([
                    'status' => GameStatus::Ended,
                    'winner_user_id' => null,
                    'ended_at' => now(),
                ]);

                broadcast(new GameEnded($game, winner: null));

                $endedCount++;
            });

        if ($lobbyDeleted > 0 || $endedCount > 0) {
            broadcast(new LobbyUpdated);
        }

        $this->info("Deleted {$lobbyDeleted} stale lobbies, ended {$endedCount} abandoned games.");

        return self::SUCCESS;
    }
}
