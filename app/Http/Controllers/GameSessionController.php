<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\GameCountdownStarted;
use App\Events\GameLobbyUpdated;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Events\PlayerHandDealt;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\JoinGameRequest;
use App\Jobs\StartGameJob;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Services\GameDealerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GameSessionController extends Controller
{
    public function store(CreateGameRequest $request): JsonResponse
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
        broadcast(new LobbyUpdated);

        $player = $game->gamePlayers()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'game' => [
                'id' => $game->id,
                'code' => $game->code,
                'status' => $game->status->value,
                'host_user_id' => $game->host_user_id,
                'variant' => $game->variant,
                'sets_count' => $game->sets_count ?? null,
                'winner_user_id' => $game->winner_user_id ?? null,
                'started_at' => $game->started_at ?? null,
            ],
            'current_player' => [
                'id' => $player->id,
                'user_id' => $player->user_id,
                'seat_index' => $player->seat_index,
                'is_ready' => $player->is_ready,
            ],
            'players' => $game->gamePlayers()->with('user')->get()->map(fn ($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user->name,
                'seat_index' => $p->seat_index,
                'is_ready' => $p->is_ready,
            ])->values()->all(),
        ])->header('X-Game-Id', (string) $game->id);
    }

    public function join(JoinGameRequest $request): JsonResponse
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
        broadcast(new LobbyUpdated);

        $player = $game->gamePlayers()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'game' => [
                'id' => $game->id,
                'code' => $game->code,
                'status' => $game->status->value,
                'host_user_id' => $game->host_user_id,
                'variant' => $game->variant,
                'sets_count' => $game->sets_count ?? null,
                'winner_user_id' => $game->winner_user_id ?? null,
                'started_at' => $game->started_at ?? null,
            ],
            'current_player' => [
                'id' => $player->id,
                'user_id' => $player->user_id,
                'seat_index' => $player->seat_index,
                'is_ready' => $player->is_ready,
            ],
            'players' => $game->gamePlayers()->with('user')->get()->map(fn ($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user->name,
                'seat_index' => $p->seat_index,
                'is_ready' => $p->is_ready,
            ])->values()->all(),
        ])->header('X-Game-Id', (string) $game->id);
    }

    public function show(GameSession $game): Response
    {
        $currentPlayer = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->first();

        $players = $game->gamePlayers()->with('user')->get()->map(fn ($player) => [
            'id' => $player->id,
            'user_id' => $player->user_id,
            'name' => $player->user->name,
            'seat_index' => $player->seat_index,
            'is_ready' => $player->is_ready,
        ])->values()->all();

        $props = [
            'game' => $game,
            'currentPlayer' => $currentPlayer,
            'players' => $players,
            'myPiles' => [],
            'centerPiles' => [],
            'opponents' => [],
        ];

        $isActiveGame = $currentPlayer && in_array($game->status, [GameStatus::Countdown, GameStatus::Playing, GameStatus::Verifying, GameStatus::Ended]);

        if ($isActiveGame) {
            $props['myPiles'] = $currentPlayer->piles()->with('pileCards.card')->get()->map(fn (Pile $pile) => [
                'id' => $pile->id,
                'pile_index' => $pile->pile_index,
                'is_completed' => $pile->is_completed,
                'cards' => $pile->pileCards->map(fn (PileCard $pc) => [
                    'id' => $pc->card->id,
                    'clothing_type' => $pc->card->clothing_type->value,
                    'color' => $pc->card->color->value,
                ])->values()->all(),
            ])->values()->all();

            $props['centerPiles'] = $game->centerPiles()->with('pileCards.card')->get()->map(fn (Pile $pile) => [
                'id' => $pile->id,
                'pile_index' => $pile->pile_index,
                'version' => $pile->version,
                'top_card' => $pile->pileCards->first() ? [
                    'id' => $pile->pileCards->first()->card->id,
                    'clothing_type' => $pile->pileCards->first()->card->clothing_type->value,
                    'color' => $pile->pileCards->first()->card->color->value,
                ] : null,
            ])->values()->all();

            $props['opponents'] = $game->gamePlayers()
                ->where('id', '!=', $currentPlayer->id)
                ->with('user', 'piles')
                ->get()
                ->map(fn ($player) => [
                    'id' => $player->id,
                    'user_id' => $player->user_id,
                    'name' => $player->user->name,
                    'seat_index' => $player->seat_index,
                    'piles' => $player->piles->map(fn (Pile $pile) => [
                        'id' => $pile->id,
                        'pile_index' => $pile->pile_index,
                        'is_completed' => $pile->is_completed,
                    ])->values()->all(),
                ])->values()->all();
        }

        return Inertia::render('Game/Show', $props);
    }

    public function ready(GameSession $game): \Illuminate\Http\Response
    {
        $player = $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $player->update(['is_ready' => ! $player->is_ready]);

        broadcast(new GameLobbyUpdated($game));

        return response()->noContent();
    }

    public function start(GameSession $game, GameDealerService $dealer): \Illuminate\Http\Response
    {
        abort_unless($game->host_user_id === auth()->id(), 403);
        abort_unless($game->status === GameStatus::Lobby, 422, 'Game is not in the lobby.');

        $players = $game->gamePlayers()->get();

        abort_if($players->count() < 2, 422, 'At least 2 players are required to start.');
        abort_unless($players->every(fn ($player) => $player->is_ready), 422, 'Not all players are ready.');

        $game->update(['status' => GameStatus::Countdown]);
        $game->gamePlayers()->update(['is_game_ready' => false]);

        $dealer->deal($game);

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

        broadcast(new LobbyUpdated);
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

        return response()->noContent();
    }

    public function clientReady(GameSession $game): \Illuminate\Http\Response
    {
        abort_unless($game->status === GameStatus::Countdown, 422, 'Game is not in the countdown phase.');

        $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail()
            ->update(['is_game_ready' => true]);

        $allReady = $game->gamePlayers()->where('is_game_ready', false)->doesntExist();

        if ($allReady) {
            $startsAt = now()->addSeconds(3);
            broadcast(new GameCountdownStarted($game, $startsAt));
            StartGameJob::dispatch($game)->delay($startsAt);
        }

        return response()->noContent();
    }

    public function leave(GameSession $game): \Illuminate\Http\Response
    {
        abort_unless($game->status === GameStatus::Lobby, 422, 'You can only leave a game in the lobby.');

        $game->gamePlayers()
            ->where('user_id', auth()->id())
            ->firstOrFail()
            ->delete();

        if ($game->gamePlayers()->count() === 0) {
            $game->delete();
        } else {
            broadcast(new GameLobbyUpdated($game));
        }

        broadcast(new LobbyUpdated);

        return response()->noContent();
    }
}
