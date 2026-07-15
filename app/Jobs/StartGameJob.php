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

        $centerPiles = $game->centerPiles()->with('pileCards.card')->get()->map(fn (Pile $pile) => [
            'id' => $pile->id,
            'pile_index' => $pile->pile_index,
            'top_card' => $pile->pileCards->first() ? [
                'id' => $pile->pileCards->first()->card->id,
                'clothing_type' => $pile->pileCards->first()->card->clothing_type->value,
                'color' => $pile->pileCards->first()->card->color->value,
            ] : null,
        ])->all();

        $allPlayers = $game->gamePlayers()->with('user', 'piles')->get()->map(fn ($player) => [
            'id' => $player->id,
            'user_id' => $player->user_id,
            'name' => $player->user->name,
            'seat_index' => $player->seat_index,
            'piles' => $player->piles->map(fn (Pile $pile) => [
                'id' => $pile->id,
                'pile_index' => $pile->pile_index,
                'is_completed' => $pile->is_completed,
            ])->all(),
        ])->all();

        broadcast(new GameStarted($game, $centerPiles, $allPlayers));

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
