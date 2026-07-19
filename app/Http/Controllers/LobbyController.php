<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\GameSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LobbyController extends Controller
{
    public function index(Request $request): Response
    {
        $games = GameSession::where('status', GameStatus::Lobby)
            ->with('host', 'gamePlayers')
            ->latest()
            ->get()
            ->map(fn ($game) => [
                'id' => $game->id,
                'code' => $game->code,
                'host_name' => $game->host->name,
                'player_count' => $game->gamePlayers->count(),
                'variant' => $game->variant,
            ]);

        $activeGame = GameSession::whereIn('status', [
            GameStatus::Countdown,
            GameStatus::Playing,
            GameStatus::Verifying,
        ])
            ->whereHas('gamePlayers', fn ($q) => $q->where('user_id', $request->user()->id))
            ->latest('updated_at')
            ->first();

        return Inertia::render('Lobby/Index', [
            'games' => $games,
            'activeGame' => $activeGame ? [
                'id' => $activeGame->id,
                'code' => $activeGame->code,
                'status' => $activeGame->status,
            ] : null,
        ]);
    }
}
