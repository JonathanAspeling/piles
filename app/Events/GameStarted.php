<?php

namespace App\Events;

use App\Models\GameSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{id: int, pile_index: int, top_card: array<string, mixed>|null}>  $centerPiles
     * @param  array<int, array{id: int, user_id: int, name: string, seat_index: int, piles: array<int, array{id: int, pile_index: int, is_completed: bool}>}>  $allPlayers
     */
    public function __construct(
        public readonly GameSession $game,
        public readonly array $centerPiles,
        public readonly array $allPlayers,
    ) {}

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
            'center_piles' => $this->centerPiles,
            'players' => $this->allPlayers,
        ];
    }
}
