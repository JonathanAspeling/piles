<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\GameLobbyUpdated;
use App\Events\LobbyUpdated;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The host filling empty seats with computer players, and taking them out
 * again, while the table is still in its lobby. Bots sit down ready.
 */
class GameBotController extends Controller
{
    public function store(GameSession $game): Response
    {
        abort_unless($game->host_user_id === auth()->id(), 403);

        DB::transaction(function () use ($game) {
            $locked = $this->lockGame($game);

            abort_unless($locked->status === GameStatus::Lobby, 422, 'Bots can only join a game in its lobby.');
            abort_if($locked->gamePlayers()->count() >= 10, 422, 'This game is full.');

            $bot = User::availableBotFor($locked);

            abort_if($bot === null, 422, 'Every bot is already at this table.');

            $locked->gamePlayers()->create([
                'user_id' => $bot->id,
                'seat_index' => $locked->gamePlayers()->max('seat_index') + 1,
                'is_ready' => true,
            ]);
        }, 3);

        broadcast(new GameLobbyUpdated($game));
        broadcast(new LobbyUpdated);

        return response()->noContent();
    }

    public function destroy(GameSession $game, GamePlayer $player): Response
    {
        abort_unless($game->host_user_id === auth()->id(), 403);

        DB::transaction(function () use ($game, $player) {
            $locked = $this->lockGame($game);

            abort_unless($locked->status === GameStatus::Lobby, 422, 'Bots can only leave a game in its lobby.');
            abort_unless($player->game_session_id === $locked->id && $player->user->is_bot, 404);

            $player->delete();
        }, 3);

        broadcast(new GameLobbyUpdated($game));
        broadcast(new LobbyUpdated);

        return response()->noContent();
    }
}
