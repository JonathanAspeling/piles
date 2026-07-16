<?php

namespace App\Events;

use App\Models\GameSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameActivated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly GameSession $game) {}

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
        return [];
    }
}
