<?php

namespace App\Http\Controllers;

use App\Enums\CardTheme;
use App\Enums\GameStatus;
use App\Events\GameCountdownStarted;
use App\Events\GameLobbyUpdated;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Events\PlayerHandDealt;
use App\Events\RematchUpdated;
use App\Exceptions\NotEnoughCardsException;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\JoinGameRequest;
use App\Jobs\StartGameJob;
use App\Models\GamePlayer;
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
            'similar_items' => $request->boolean('similar_items'),
            'theme' => $request->enum('theme', CardTheme::class) ?? CardTheme::Standard,
            'hard_variant' => $request->boolean('hard_variant'),
        ]);

        $game->gamePlayers()->create([
            'user_id' => $request->user()->id,
            'seat_index' => 0,
            'is_ready' => false,
        ]);

        broadcast(new GameLobbyUpdated($game));
        broadcast(new LobbyUpdated);

        $player = $game->gamePlayers()->where('user_id', $request->user()->id)->first();

        return $this->joinedGameResponse($game, $player);
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

        return $this->joinedGameResponse($game, $player);
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
            'is_bot' => $player->user->is_bot,
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
            'rematch' => $game->rematchPayload(),
        ];

        $isActiveGame = $currentPlayer && in_array($game->status, [GameStatus::Countdown, GameStatus::Playing, GameStatus::Verifying, GameStatus::Ended]);

        if ($isActiveGame) {
            $props['myPiles'] = $currentPlayer->piles()->with('pileCards.card')->get()
                ->map(fn (Pile $pile) => $pile->toPlayerPayload())
                ->values()
                ->all();

            $props['centerPiles'] = $game->centerPilesPayload();
            $props['opponents'] = $game->opponentsPayload($currentPlayer);
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
                // Bots have no screen to load, so the countdown never waits on them.
                $locked->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', true))->update(['is_game_ready' => true]);

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

        $allPlayers = $game->gamePlayers()->with('user', 'piles.pileCards.card')->get()->map(fn ($player) => [
            'id' => $player->id,
            'user_id' => $player->user_id,
            'name' => $player->user->name,
            'is_bot' => $player->user->is_bot,
            'seat_index' => $player->seat_index,
            'piles' => $player->piles->map(fn (Pile $pile) => [
                'id' => $pile->id,
                'pile_index' => $pile->pile_index,
                'is_completed' => $pile->is_completed,
                // A pile dealt four of a kind starts complete, and complete
                // piles are public.
                'completed_type' => $pile->is_completed
                    ? $pile->pileCards->first()?->card->clothing_type->value
                    : null,
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

        // One transaction (and so, on SQLite, one writer at a time): of several
        // players confirming at once, exactly one sees the table complete and
        // starts the countdown. The client retries this until it lands, and a
        // repeat from a player already marked ready changes nothing.
        $startsCountdown = DB::transaction(function () use ($game): bool {
            $player = $game->gamePlayers()
                ->where('user_id', auth()->id())
                ->firstOrFail();

            if ($player->is_game_ready) {
                return false;
            }

            $player->update(['is_game_ready' => true]);

            return $game->gamePlayers()->where('is_game_ready', false)->doesntExist();
        }, 3);

        if ($startsCountdown) {
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

            // Bots don't keep a room open on their own.
            if ($locked->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', false))->doesntExist()) {
                $locked->gamePlayers()->delete();
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
     * Ask for a rematch of an ended game: the first player to ask opens a new
     * lobby with the same settings, and everyone after joins that lobby.
     * Asking twice is harmless: you're simply already in.
     */
    public function rematch(GameSession $game): JsonResponse
    {
        [$rematch, $player] = DB::transaction(function () use ($game) {
            $locked = $this->lockGame($game);

            abort_unless($locked->status === GameStatus::Ended, 422, 'This game has not ended yet.');
            abort_unless($locked->gamePlayers()->where('user_id', auth()->id())->exists(), 403);

            if ($locked->rematch_game_session_id === null) {
                $rematch = GameSession::create([
                    'code' => strtoupper(Str::random(6)),
                    'status' => GameStatus::Lobby,
                    'host_user_id' => auth()->id(),
                    'variant' => $locked->variant,
                    'similar_items' => $locked->similar_items,
                    'theme' => $locked->theme,
                    'hard_variant' => $locked->hard_variant,
                ]);

                $locked->update(['rematch_game_session_id' => $rematch->id]);

                // The bots come back for another round, ready as before.
                $locked->gamePlayers()
                    ->whereHas('user', fn ($query) => $query->where('is_bot', true))
                    ->orderBy('seat_index')
                    ->get()
                    ->each(fn (GamePlayer $bot, int $index) => $rematch->gamePlayers()->create([
                        'user_id' => $bot->user_id,
                        'seat_index' => $index + 1,
                        'is_ready' => true,
                    ]));
            } else {
                $rematch = $this->lockGame($locked->rematchGame);
            }

            $existing = $rematch->gamePlayers()->where('user_id', auth()->id())->first();

            if ($existing) {
                return [$rematch, $existing];
            }

            abort_unless($rematch->status === GameStatus::Lobby, 422, 'That rematch has already started.');
            abort_if($rematch->gamePlayers()->count() >= 10, 422, 'That rematch is full.');

            // The first person back takes the head of the table, even with bots already seated.
            $hasPerson = $rematch->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', false))->exists();
            $nextSeat = $hasPerson ? $rematch->gamePlayers()->max('seat_index') + 1 : 0;

            return [$rematch, $rematch->gamePlayers()->create([
                'user_id' => auth()->id(),
                'seat_index' => $nextSeat,
                'is_ready' => false,
            ])];
        }, 3);

        broadcast(new GameLobbyUpdated($rematch));
        broadcast(new RematchUpdated($game->fresh()));
        broadcast(new LobbyUpdated);

        return $this->joinedGameResponse($rematch, $player);
    }

    /** The game, your seat and the table: what the client needs to open a game's lobby. */
    private function joinedGameResponse(GameSession $game, GamePlayer $player): JsonResponse
    {
        return response()->json([
            'game' => [
                'id' => $game->id,
                'code' => $game->code,
                'status' => $game->status->value,
                'host_user_id' => $game->host_user_id,
                'variant' => $game->variant,
                'similar_items' => $game->similar_items,
                'theme' => $game->theme->value,
                'hard_variant' => $game->hard_variant,
                'background_seed' => $game->background_seed,
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
                'is_bot' => $p->user->is_bot,
                'seat_index' => $p->seat_index,
                'is_ready' => $p->is_ready,
            ])->values()->all(),
        ])->header('X-Game-Id', (string) $game->id);
    }
}
