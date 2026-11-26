<?php

use App\Enums\GameStatus;
use App\Events\GameCountdownStarted;
use App\Events\GameLobbyUpdated;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Events\PlayerHandDealt;
use App\Events\RematchUpdated;
use App\Jobs\BotMoveJob;
use App\Jobs\StartGameJob;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\User;
use App\Services\PlayerHistory;
use Database\Seeders\CardSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Event::fake([GameLobbyUpdated::class, LobbyUpdated::class, GameCountdownStarted::class, GameStarted::class, PlayerHandDealt::class, RematchUpdated::class]);
    $this->host = User::factory()->create(['name' => 'Jonathan']);
    $this->game = GameSession::create(['code' => 'BOTLOB', 'status' => GameStatus::Lobby, 'host_user_id' => $this->host->id, 'variant' => false]);
    $this->game->gamePlayers()->create(['user_id' => $this->host->id, 'seat_index' => 0, 'is_ready' => false]);
});

function addBot(GameSession $game, User $as): TestResponse
{
    return test()->actingAs($as)->post(route('games.bots.store', $game));
}

test('the host can add a bot, which sits down ready', function () {
    addBot($this->game, $this->host)->assertNoContent();

    $seat = $this->game->gamePlayers()->with('user')->where('seat_index', 1)->first();
    expect($seat->user->is_bot)->toBeTrue()
        ->and($seat->user->name)->toBe(User::BOT_NAMES[0])
        ->and($seat->is_ready)->toBeTrue();
    Event::assertDispatched(GameLobbyUpdated::class);
    Event::assertDispatched(LobbyUpdated::class);
});

test('each bot at a table has its own name, and the accounts are reused across games', function () {
    addBot($this->game, $this->host);
    addBot($this->game, $this->host);

    $other = GameSession::create(['code' => 'BOTLB2', 'status' => GameStatus::Lobby, 'host_user_id' => $this->host->id, 'variant' => false]);
    $other->gamePlayers()->create(['user_id' => $this->host->id, 'seat_index' => 0, 'is_ready' => false]);
    addBot($other, $this->host);

    expect($this->game->gamePlayers()->with('user')->get()->pluck('user.name')->all())->toBe(['Jonathan', User::BOT_NAMES[0], User::BOT_NAMES[1]])
        ->and(User::where('is_bot', true)->count())->toBe(2);
});

test('only the host can add or remove bots', function () {
    $guest = User::factory()->create();
    $this->game->gamePlayers()->create(['user_id' => $guest->id, 'seat_index' => 1, 'is_ready' => false]);
    addBot($this->game, $this->host);
    $bot = $this->game->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', true))->first();

    addBot($this->game, $guest)->assertForbidden();
    $this->actingAs($guest)->delete(route('games.bots.destroy', [$this->game, $bot]))->assertForbidden();
});

test('bots can only be added to a lobby with a free seat', function () {
    for ($seat = 1; $seat < 10; $seat++) {
        $this->game->gamePlayers()->create(['user_id' => User::factory()->create()->id, 'seat_index' => $seat, 'is_ready' => false]);
    }
    addBot($this->game, $this->host)->assertStatus(422);

    $this->game->gamePlayers()->where('seat_index', 9)->delete();
    $this->game->update(['status' => GameStatus::Playing]);
    addBot($this->game, $this->host)->assertStatus(422);
});

test('the host can take a bot out again, but not a person', function () {
    $guest = User::factory()->create();
    $person = $this->game->gamePlayers()->create(['user_id' => $guest->id, 'seat_index' => 1, 'is_ready' => false]);
    addBot($this->game, $this->host);
    $bot = $this->game->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', true))->first();

    $this->actingAs($this->host)->delete(route('games.bots.destroy', [$this->game, $person]))->assertNotFound();
    $this->actingAs($this->host)->delete(route('games.bots.destroy', [$this->game, $bot]))->assertNoContent();

    expect(GamePlayer::find($bot->id))->toBeNull()
        ->and(GamePlayer::find($person->id))->not->toBeNull();
});

test('players say whether they are bots', function () {
    addBot($this->game, $this->host);

    $this->actingAs($this->host)
        ->get(route('games.show', $this->game))
        ->assertInertia(fn (Assert $page) => $page
            ->where('players.0.is_bot', false)
            ->where('players.1.is_bot', true));

    expect((new GameLobbyUpdated($this->game))->broadcastWith()['players'][1]['is_bot'])->toBeTrue();
});

test('you can start a game against bots alone, and the countdown only waits for you', function () {
    $this->seed(CardSeeder::class);
    Queue::fake();
    addBot($this->game, $this->host);
    $this->game->gamePlayers()->where('user_id', $this->host->id)->update(['is_ready' => true]);

    $this->actingAs($this->host)->post(route('games.start', $this->game))->assertNoContent();

    expect($this->game->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', true))->value('is_game_ready'))->toBeTrue();

    $this->actingAs($this->host)->post(route('games.client-ready', $this->game))->assertNoContent();

    Event::assertDispatched(GameCountdownStarted::class);
    Queue::assertPushed(StartGameJob::class);
});

test('bots start playing at GO', function () {
    Queue::fake();
    addBot($this->game, $this->host);
    addBot($this->game, $this->host);
    $this->game->update(['status' => GameStatus::Countdown]);

    (new StartGameJob($this->game))->handle();

    $botSeats = $this->game->gamePlayers()->whereHas('user', fn ($query) => $query->where('is_bot', true))->pluck('id')->all();
    Queue::assertPushed(BotMoveJob::class, 2);
    Queue::assertPushed(BotMoveJob::class, fn (BotMoveJob $job) => in_array($job->gamePlayerId, $botSeats, true) && $job->movesMade === 0);
});

test('when the last person leaves, the room closes even with bots in it', function () {
    addBot($this->game, $this->host);

    $this->actingAs($this->host)->delete(route('games.leave', $this->game))->assertNoContent();

    expect(GameSession::find($this->game->id))->toBeNull()
        ->and(GamePlayer::where('game_session_id', $this->game->id)->count())->toBe(0);
});

test('Play again brings the bots back, ready, with you at the head of the table', function () {
    addBot($this->game, $this->host);
    $this->game->update(['status' => GameStatus::Ended]);

    $this->actingAs($this->host)->postJson(route('games.rematch', $this->game))->assertOk();

    $rematch = $this->game->fresh()->rematchGame;
    $seats = $rematch->gamePlayers()->with('user')->orderBy('seat_index')->get();
    expect($seats->pluck('user.name')->all())->toBe(['Jonathan', User::BOT_NAMES[0]])
        ->and($seats[0]->seat_index)->toBe(0)
        ->and($seats[1]->is_ready)->toBeTrue();
});

test('games with bots are left out of your stats, but still listed', function () {
    $friend = User::factory()->create(['name' => 'Cheron']);
    $bot = User::factory()->bot()->create(['name' => 'Gizmo']);

    $play = function (array $players, User $winner, string $endedAt) {
        $game = GameSession::create([
            'code' => strtoupper(Str::random(6)),
            'status' => GameStatus::Ended,
            'host_user_id' => $this->host->id,
            'variant' => false,
            'winner_user_id' => $winner->id,
            'started_at' => Carbon::parse($endedAt)->subSeconds(60),
            'ended_at' => Carbon::parse($endedAt),
        ]);
        foreach ($players as $seat => $player) {
            $game->gamePlayers()->create(['user_id' => $player->id, 'seat_index' => $seat, 'is_ready' => true]);
        }
    };
    $play([$this->host, $friend], $friend, '2026-11-20 20:00');
    $play([$this->host, $bot], $this->host, '2026-11-21 20:00');

    $record = (new PlayerHistory)->for($this->host);

    expect($record['played'])->toBe(1)
        ->and($record['won'])->toBe(0)
        ->and($record['win_streak'])->toBe(0)
        ->and($record['fastest_win_seconds'])->toBeNull()
        ->and(collect($record['rivals'])->pluck('name')->all())->toBe(['Cheron'])
        ->and(collect($record['recent'])->pluck('with_bots')->all())->toBe([true, false]);
});
