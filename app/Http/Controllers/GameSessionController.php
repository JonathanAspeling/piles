<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\GameCountdownStarted;
use App\Events\GameLobbyUpdated;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\JoinGameRequest;
use App\Jobs\StartGameJob;
use App\Models\GameSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GameSessionController extends Controller
{
    public function store(CreateGameRequest $request): RedirectResponse
    {
        $code = strtoupper(Str::random(6));

        $game = GameSession::create([
            'code' => $code,
            'status' => GameStatus::Lobby,
            'host_user_id' => $request->user()->id,
            'variant' => $request->boolean('variant'),
        ]);

        $game->gamePlayers()->create([
            'user_id' => $request->user()->id,
            'seat_index' => 0,
            'is_ready' => false,
        ]);

        broadcast(new GameLobbyUpdated($game));

        return redirect()->route('games.show', $game);
    }

    public function join(JoinGameRequest $request): RedirectResponse
    {
        $game = GameSession::where('code', strtoupper($request->input('code')))
            ->where('status', GameStatus::Lobby)
            ->firstOrFail();

        $alreadyJoined = $game->gamePlayers()->where('user_id', $request->user()->id)->exists();

        abort_if($alreadyJoined, 422, 'You are already in this game.');
        abort_if($game->gamePlayers()->count() >= 7, 422, 'This game is full.');

        $nextSeat = $game->gamePlayers()->max('seat_index') + 1;

        $game->gamePlayers()->create([
            'user_id' => $request->user()->id,
            'seat_index' => $nextSeat,
            'is_ready' => false,
        ]);

        broadcast(new GameLobbyUpdated($game));

        return redirect()->route('games.show', $game);
    }

    public function show(GameSession $game): Response
    {
        $currentPlayer = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->first();

        return Inertia::render('Game/Show', [
            'game' => $game,
            'currentPlayer' => $currentPlayer,
        ]);
    }

    public function ready(GameSession $game): RedirectResponse
    {
        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $player->update(['is_ready' => ! $player->is_ready]);

        broadcast(new GameLobbyUpdated($game));

        return redirect()->route('games.show', $game);
    }

    public function start(GameSession $game): RedirectResponse
    {
        abort_unless($game->host_user_id === auth()->id(), 403);
        abort_unless($game->status === GameStatus::Lobby, 422, 'Game is not in the lobby.');

        $players = $game->gamePlayers()->get();

        abort_if($players->count() < 2, 422, 'At least 2 players are required to start.');
        abort_unless($players->every(fn ($player) => $player->is_ready), 422, 'Not all players are ready.');

        $startsAt = now()->addSeconds(3);

        $game->update(['status' => GameStatus::Countdown]);

        broadcast(new GameCountdownStarted($game, $startsAt));

        StartGameJob::dispatch($game)->delay($startsAt);

        return redirect()->route('games.show', $game);
    }

    public function leave(GameSession $game): RedirectResponse
    {
        abort_unless($game->status === GameStatus::Lobby, 422, 'You can only leave a game in the lobby.');

        $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail()
            ->delete();

        broadcast(new GameLobbyUpdated($game));

        return redirect()->route('lobby.index');
    }
}
