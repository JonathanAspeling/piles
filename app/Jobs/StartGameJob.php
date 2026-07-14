<?php

namespace App\Jobs;

use App\Enums\GameStatus;
use App\Events\GameStarted;
use App\Events\PlayerHandDealt;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Services\GameDealerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StartGameJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly GameSession $game) {}

    public function handle(GameDealerService $dealer): void
    {
        $game = $this->game->fresh();

        if ($game->status !== GameStatus::Countdown) {
            return;
        }

        $dealer->deal($game);
        $game->update(['status' => GameStatus::Playing]);

        $centerPiles = $game->centerPiles()->get()->map(fn (Pile $pile) => [
            'id' => $pile->id,
            'pile_index' => $pile->pile_index,
        ])->all();

        broadcast(new GameStarted($game, $centerPiles));

        foreach ($game->gamePlayers()->with('piles.pileCards.card')->get() as $player) {
            $pilesData = $player->piles->map(fn (Pile $pile) => [
                'id' => $pile->id,
                'pile_index' => $pile->pile_index,
                'is_completed' => $pile->is_completed,
                'cards' => $pile->pileCards->map(fn (PileCard $pc) => [
                    'id' => $pc->card->id,
                    'clothing_type' => $pc->card->clothing_type->value,
                    'color' => $pc->card->color->value,
                ])->all(),
            ])->all();

            broadcast(new PlayerHandDealt($player, $pilesData));
        }
    }
}
