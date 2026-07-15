<?php

namespace App\Events;

use App\Enums\GameStatus;
use App\Models\GameSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LobbyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('lobby')];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $games = GameSession::where('status', GameStatus::Lobby)
            ->with('host', 'gamePlayers')
            ->latest()
            ->get()
            ->map(fn ($game) => [
                'id' => $game->id,
                'code' => $game->code,
                'host_name' => $game->host->name,
                'player_count' => $game->gamePlayers->count(),
                'variant' => $game->variant,
            ])
            ->values()
            ->all();

        return ['games' => $games];
    }
}
