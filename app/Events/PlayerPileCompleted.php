<?php

namespace App\Events;

use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerPileCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly GameSession $game,
        public readonly GamePlayer $player,
        public readonly Pile $pile,
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
        $cards = $this->pile->pileCards()->with('card')->get()->map(fn (PileCard $pc) => [
            'id' => $pc->card->id,
            'clothing_type' => $pc->card->clothing_type->value,
            'color' => $pc->card->color->value,
        ])->all();

        return [
            'game_player_id' => $this->player->id,
            'pile_id' => $this->pile->id,
            'pile_index' => $this->pile->pile_index,
            'cards' => $cards,
        ];
    }
}
