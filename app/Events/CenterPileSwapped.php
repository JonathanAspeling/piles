<?php

namespace App\Events;

use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The committed result of a swap: a centre pile's new top card and version.
 *
 * Clients show swaps optimistically and whisper them to each other, but when
 * several players swap at once those whispers (and the cancels from the ones
 * who lost) arrive in any order. This is the authoritative word that every
 * screen converges on.
 */
class CenterPileSwapped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly GameSession $game,
        public readonly Pile $centerPile,
        public readonly Card $topCard,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("game.{$this->game->id}"),
        ];
    }

    /** @return array{center_pile_id: int, version: int, top_card: array{id: int, clothing_type: int, color: int}} */
    public function broadcastWith(): array
    {
        return [
            'center_pile_id' => $this->centerPile->id,
            'version' => $this->centerPile->version,
            'top_card' => [
                'id' => $this->topCard->id,
                'clothing_type' => $this->topCard->clothing_type->value,
                'color' => $this->topCard->color->value,
            ],
        ];
    }
}
