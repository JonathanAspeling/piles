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

class CenterCardSwapped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly GameSession $game,
        public readonly Pile $centerPile,
        public readonly Card $incomingCard,
        public readonly int $outgoingCardId,
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
            'center_pile_id' => $this->centerPile->id,
            'center_pile_version' => $this->centerPile->version,
            'incoming_card' => [
                'id' => $this->incomingCard->id,
                'clothing_type' => $this->incomingCard->clothing_type->value,
                'color' => $this->incomingCard->color->value,
            ],
            'outgoing_card_id' => $this->outgoingCardId,
        ];
    }
}
