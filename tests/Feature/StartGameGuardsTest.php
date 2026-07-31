<?php

use App\Enums\GameStatus;
use App\Events\GameLobbyUpdated;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Events\PlayerHandDealt;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\User;
use Database\Seeders\CardSeeder;
use Illuminate\Support\Facades\Event;

function makeStartableGame(int $playerCount): GameSession
{
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'STRT'.str_pad((string) $playerCount, 2, '0', STR_PAD_LEFT),
        'status' => GameStatus::Lobby,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    for ($i = 0; $i < $playerCount; $i++) {
        $game->gamePlayers()->create([
            'user_id' => $i === 0 ? $host->id : User::factory()->create()->id,
            'seat_index' => $i,
            'is_ready' => true,
        ]);
    }

    return $game;
}

beforeEach(function () {
    $this->seed(CardSeeder::class);
    Event::fake([GameStarted::class, PlayerHandDealt::class, GameLobbyUpdated::class, LobbyUpdated::class]);
});

test('starting an 8 player game deals every seat a full hand', function () {
    $game = makeStartableGame(8);

    $this->actingAs($game->host)
        ->postJson(route('games.start', $game))
        ->assertNoContent();

    foreach ($game->gamePlayers()->with('piles.pileCards')->get() as $player) {
        expect($player->piles->flatMap->pileCards)->toHaveCount(24, "seat {$player->seat_index}");
    }

    Event::assertDispatchedTimes(PlayerHandDealt::class, 8);
});

test('a player cannot join once the host has started the game', function () {
    $game = makeStartableGame(7);

    $this->actingAs($game->host)->postJson(route('games.start', $game))->assertNoContent();

    $latecomer = User::factory()->create();

    $this->actingAs($latecomer)
        ->postJson(route('games.join'), ['code' => $game->code])
        ->assertStatus(422);

    // A player row created after the deal would sit in a live game holding no cards.
    expect($game->gamePlayers()->count())->toBe(7);
});

test('starting a game twice does not deal a second hand', function () {
    $game = makeStartableGame(8);

    $this->actingAs($game->host)->postJson(route('games.start', $game))->assertNoContent();
    $this->actingAs($game->host)->postJson(route('games.start', $game))->assertStatus(422);

    expect($game->piles()->count())->toBe(8 * 6 + 8);
});

test('the host gets an error instead of a half dealt game when sets are missing', function () {
    // 47 complete sets — what a card table seeded before the 16-set expansion holds.
    Card::where('clothing_type', '>=', 47)->delete();

    $game = makeStartableGame(8);

    $this->actingAs($game->host)
        ->postJson(route('games.start', $game))
        ->assertStatus(422);

    expect($game->fresh()->status)->toBe(GameStatus::Lobby)
        ->and($game->piles()->count())->toBe(0);

    Event::assertNotDispatched(GameStarted::class);
});

test('a player cannot leave once the host has started the game', function () {
    $game = makeStartableGame(8);
    $player = $game->gamePlayers()->where('seat_index', 7)->first();

    $this->actingAs($game->host)->postJson(route('games.start', $game))->assertNoContent();

    $this->actingAs($player->user)
        ->deleteJson(route('games.leave', $game))
        ->assertStatus(422);

    expect($game->gamePlayers()->count())->toBe(8);
});
