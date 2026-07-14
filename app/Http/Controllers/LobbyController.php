<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Models\GameSession;
use Inertia\Inertia;
use Inertia\Response;

class LobbyController extends Controller
{
    public function index(): Response
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

        return Inertia::render('Lobby/Index', ['games' => $games]);
    }
}
