<?php

namespace App\Events;

use App\Models\GamePlayer;
use App\Models\GameSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly GameSession $game,
        public readonly ?GamePlayer $winner,
        public readonly ?string $forfeitedBy = null,
    ) {
        $this->winner?->loadMissing('user');
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("game.{$this->game->id}"),
        ];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'winner' => $this->winner ? [
                'game_player_id' => $this->winner->id,
                'user_id' => $this->winner->user_id,
                'name' => $this->winner->user->name,
            ] : null,
            'forfeited_by' => $this->forfeitedBy,
        ];
    }
}
