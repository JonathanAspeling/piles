<?php

namespace App\Events;

use App\Models\GameSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells everyone still on an ended game's screen who has moved on to its
 * rematch lobby, so the end panel can show "Ada is ready for another round".
 */
class RematchUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly GameSession $endedGame) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("game.{$this->endedGame->id}"),
        ];
    }

    /** @return array{rematch_game_id: int|null, user_ids: array<int, int>} */
    public function broadcastWith(): array
    {
        return $this->endedGame->rematchPayload();
    }
}
