<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Events\GameCountdownStarted;
use App\Events\GameLobbyUpdated;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Events\PlayerHandDealt;
use App\Exceptions\NotEnoughCardsException;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\JoinGameRequest;
use App\Jobs\StartGameJob;
use App\Models\GameSession;
use App\Models\Pile;
use App\Services\GameDealerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        $game = GameSession::where('code', strtoupper($request->input('code')))->firstOrFail();

        $player = DB::transaction(function () use ($game, $request) {
            $locked = $this->lockGame($game);

            abort_unless($locked->status === GameStatus::Lobby, 422, 'This game has already started.');

            $alreadyJoined = $locked->gamePlayers()->where('user_id', $request->user()->id)->exists();

            abort_if($alreadyJoined, 422, 'You are already in this game.');
            abort_if($locked->gamePlayers()->count() >= 10, 422, 'This game is full.');

            $nextSeat = $locked->gamePlayers()->max('seat_index') + 1;

            return $locked->gamePlayers()->create([
                'user_id' => $request->user()->id,
                'seat_index' => $nextSeat,
                'is_ready' => false,
            ]);
        }, 3);

        broadcast(new GameLobbyUpdated($game));
        broadcast(new LobbyUpdated);

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
            $props['myPiles'] = $currentPlayer->piles()->with('pileCards.card')->get()
                ->map(fn (Pile $pile) => $pile->toPlayerPayload())
                ->values()
                ->all();

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
                ->with('user', 'piles.pileCards.card')
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
                        // Completed piles are public — they're revealed by
                        // PlayerPileCompleted the moment they finish. An
                        // in-progress pile's contents stay private to its owner,
                        // so only the completed ones surrender a clothing type.
                        'completed_type' => $pile->is_completed
                            ? $pile->pileCards->first()?->card->clothing_type->value
                            : null,
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

        try {
            DB::transaction(function () use ($game, $dealer) {
                $locked = $this->lockGame($game);

                abort_unless($locked->status === GameStatus::Lobby, 422, 'Game is not in the lobby.');

                $players = $locked->gamePlayers()->get();

                abort_if($players->count() < 2, 422, 'At least 2 players are required to start.');
                abort_unless($players->every(fn ($player) => $player->is_ready), 422, 'Not all players are ready.');

                $locked->update(['status' => GameStatus::Countdown]);
                $locked->gamePlayers()->update(['is_game_ready' => false]);

                $dealer->deal($locked);
            }, 3);
        } catch (NotEnoughCardsException $e) {
            abort(422, $e->getMessage());
        }

        $game->refresh();

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
                // Nothing is complete at deal time; kept for shape parity with
                // the payload Game/Show renders on a refresh.
                'completed_type' => null,
            ])->all(),
        ])->all();

        broadcast(new LobbyUpdated);
        broadcast(new GameStarted($game, $centerPiles, $allPlayers));

        foreach ($game->gamePlayers()->with('piles.pileCards.card')->get() as $player) {
            $pilesData = $player->piles->map(fn (Pile $pile) => $pile->toPlayerPayload())->all();

            broadcast(new PlayerHandDealt($player, $pilesData));
        }

        return response()->noContent();
    }

    public function status(GameSession $game): JsonResponse
    {
        $game->loadMissing('winner');

        return response()->json([
            'status' => $game->status->value,
            'winner' => $game->winner ? [
                'game_player_id' => $game->gamePlayers()->where('user_id', $game->winner_user_id)->value('id'),
                'user_id' => $game->winner_user_id,
                'name' => $game->winner->name,
            ] : null,
        ]);
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
            broadcast(new GameCountdownStarted($game, 3000));
            StartGameJob::dispatch($game)->delay(now()->addSeconds(3));
        }

        return response()->noContent();
    }

    public function leave(GameSession $game): \Illuminate\Http\Response
    {
        $gameRemains = DB::transaction(function () use ($game) {
            $locked = $this->lockGame($game);

            abort_unless($locked->status === GameStatus::Lobby, 422, 'You can only leave a game in the lobby.');

            $locked->gamePlayers()
                ->where('user_id', auth()->id())
                ->firstOrFail()
                ->delete();

            if ($locked->gamePlayers()->count() === 0) {
                $locked->delete();

                return false;
            }

            return true;
        }, 3);

        if ($gameRemains) {
            broadcast(new GameLobbyUpdated($game));
        }

        broadcast(new LobbyUpdated);

        return response()->noContent();
    }

    /**
     * Claim the game row's write lock and return its freshly committed state.
     *
     * Must be called inside a transaction. Lobby transitions (join, start, leave)
     * are otherwise a check-then-act race: a join that reads "lobby" microseconds
     * before the host starts still inserts its player row, and if that row lands
     * after the deal, that player sits in a live game holding no cards. SQLite
     * ignores `SELECT ... FOR UPDATE`, so the row is claimed by writing to it —
     * concurrent transactions then block here, and the read below is guaranteed
     * to see committed state rather than the caller's stale snapshot.
     */
    private function lockGame(GameSession $game): GameSession
    {
        GameSession::whereKey($game->getKey())->update(['updated_at' => now()]);

        return GameSession::whereKey($game->getKey())->firstOrFail();
    }
}
