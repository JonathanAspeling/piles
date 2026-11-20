<?php

use App\Enums\GameStatus;
use App\Events\GameLobbyUpdated;
use App\Events\LobbyUpdated;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Event::fake([GameLobbyUpdated::class, LobbyUpdated::class]);
});

test('similar items is off by default', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('games.store'))
        ->assertOk()
        ->assertJsonPath('game.similar_items', false);

    expect(GameSession::sole()->similar_items)->toBeFalse();
});

test('the host can turn on similar items when creating the game', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('games.store'), ['similar_items' => true])
        ->assertOk()
        ->assertJsonPath('game.similar_items', true);

    expect(GameSession::sole()->similar_items)->toBeTrue();
});

test('a non-boolean similar items value is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('games.store'), ['similar_items' => 'lots'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('similar_items');

    expect(GameSession::count())->toBe(0);
});

test('the old variant setting is no longer accepted as similar items', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('games.store'), ['variant' => true])
        ->assertOk()
        ->assertJsonPath('game.similar_items', false)
        ->assertJsonMissingPath('game.variant');
});

test('players and the open games list see the similar items setting', function () {
    $host = User::factory()->create();
    $game = GameSession::create([
        'code' => 'SIMLR1',
        'status' => GameStatus::Lobby,
        'host_user_id' => $host->id,
        'similar_items' => true,
    ]);
    $game->gamePlayers()->create(['user_id' => $host->id, 'seat_index' => 0, 'is_ready' => false]);

    $guest = User::factory()->create();

    $this->actingAs($guest)
        ->postJson(route('games.join'), ['code' => $game->code])
        ->assertOk()
        ->assertJsonPath('game.similar_items', true);

    $this->actingAs($guest)
        ->get(route('games.show', $game))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('game.similar_items', true));

    $this->actingAs(User::factory()->create())
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('games.0.similar_items', true));

    expect((new LobbyUpdated)->broadcastWith()['games'][0])
        ->toMatchArray(['id' => $game->id, 'similar_items' => true])
        ->not->toHaveKey('variant');
});
