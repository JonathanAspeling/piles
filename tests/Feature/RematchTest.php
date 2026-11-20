<?php

use App\Enums\CardTheme;
use App\Enums\GameStatus;
use App\Events\GameLobbyUpdated;
use App\Events\RematchUpdated;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

/**
 * @param  array<int, User>  $players
 * @param  array<string, mixed>  $attributes
 */
function createEndedGame(array $players, array $attributes = []): GameSession
{
    $game = GameSession::create([
        'code' => 'ENDED1',
        'status' => GameStatus::Ended,
        'host_user_id' => $players[0]->id,
        'variant' => false,
        ...$attributes,
    ]);

    foreach ($players as $seat => $player) {
        $game->gamePlayers()->create([
            'user_id' => $player->id,
            'seat_index' => $seat,
            'is_ready' => true,
        ]);
    }

    return $game;
}

test('the first player to ask for a rematch opens a lobby with the same settings', function () {
    Event::fake([GameLobbyUpdated::class, RematchUpdated::class]);

    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben], ['variant' => true, 'theme' => CardTheme::Classic, 'hard_variant' => true]);

    $response = $this->actingAs($ben)
        ->postJson(route('games.rematch', $game))
        ->assertOk();

    $rematch = GameSession::findOrFail($response->json('game.id'));

    expect($rematch->id)->not->toBe($game->id)
        ->and($rematch->status)->toBe(GameStatus::Lobby)
        ->and($rematch->host_user_id)->toBe($ben->id)
        ->and($rematch->variant)->toBeTrue()
        ->and($rematch->theme)->toBe(CardTheme::Classic)
        ->and($rematch->hard_variant)->toBeTrue()
        ->and($rematch->gamePlayers()->pluck('user_id')->all())->toBe([$ben->id])
        ->and($game->fresh()->rematch_game_session_id)->toBe($rematch->id);

    expect($response->json('current_player.user_id'))->toBe($ben->id)
        ->and($response->json('current_player.seat_index'))->toBe(0);

    Event::assertDispatched(RematchUpdated::class, fn (RematchUpdated $event) => $event->broadcastWith() === [
        'rematch_game_id' => $rematch->id,
        'user_ids' => [$ben->id],
    ]);
    Event::assertDispatched(GameLobbyUpdated::class, fn (GameLobbyUpdated $event) => $event->game->is($rematch));
});

test('later players join the same rematch lobby', function () {
    Event::fake([GameLobbyUpdated::class, RematchUpdated::class]);

    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben]);

    $first = $this->actingAs($ben)->postJson(route('games.rematch', $game))->json('game.id');
    $second = $this->actingAs($ada)->postJson(route('games.rematch', $game))->assertOk();

    expect($second->json('game.id'))->toBe($first)
        ->and($second->json('current_player.seat_index'))->toBe(1)
        ->and(GameSession::findOrFail($first)->gamePlayers()->count())->toBe(2)
        ->and($game->fresh()->rematchPayload())->toBe(['rematch_game_id' => $first, 'user_ids' => [$ben->id, $ada->id]]);
});

test('asking for a rematch twice keeps your seat', function () {
    Event::fake([GameLobbyUpdated::class, RematchUpdated::class]);

    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben]);

    $first = $this->actingAs($ada)->postJson(route('games.rematch', $game))->json();
    $second = $this->actingAs($ada)->postJson(route('games.rematch', $game))->assertOk()->json();

    expect($second['game']['id'])->toBe($first['game']['id'])
        ->and($second['current_player']['id'])->toBe($first['current_player']['id'])
        ->and(GameSession::findOrFail($first['game']['id'])->gamePlayers()->count())->toBe(1);
});

test('a rematch can only be asked for once the game has ended', function () {
    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben], ['status' => GameStatus::Playing]);

    $this->actingAs($ada)->postJson(route('games.rematch', $game))->assertStatus(422);

    expect($game->fresh()->rematch_game_session_id)->toBeNull();
});

test('only the ended game\'s players can ask for its rematch', function () {
    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $stranger = User::factory()->create();
    $game = createEndedGame([$ada, $ben]);

    $this->actingAs($stranger)->postJson(route('games.rematch', $game))->assertForbidden();

    expect($game->fresh()->rematch_game_session_id)->toBeNull();
});

test('a rematch that has already started turns latecomers away', function () {
    Event::fake([GameLobbyUpdated::class, RematchUpdated::class]);

    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben]);

    $rematchId = $this->actingAs($ben)->postJson(route('games.rematch', $game))->json('game.id');
    GameSession::whereKey($rematchId)->update(['status' => GameStatus::Playing]);

    $this->actingAs($ada)->postJson(route('games.rematch', $game))->assertStatus(422);
});

test('a rematch lobby that empties lets the next request open a fresh one', function () {
    Event::fake([GameLobbyUpdated::class, RematchUpdated::class]);

    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben]);

    $abandoned = $this->actingAs($ben)->postJson(route('games.rematch', $game))->json('game.id');
    $this->actingAs($ben)->deleteJson(route('games.leave', $abandoned))->assertNoContent();

    expect(GameSession::find($abandoned))->toBeNull()
        ->and($game->fresh()->rematch_game_session_id)->toBeNull();

    $fresh = $this->actingAs($ada)->postJson(route('games.rematch', $game))->assertOk()->json('game.id');

    expect($fresh)->not->toBe($abandoned)
        ->and($game->fresh()->rematch_game_session_id)->toBe($fresh);
});

test('the game page tells an ended game\'s players who is in the rematch', function () {
    Event::fake([GameLobbyUpdated::class, RematchUpdated::class]);

    $ada = User::factory()->create();
    $ben = User::factory()->create();
    $game = createEndedGame([$ada, $ben]);

    $this->actingAs($ada)
        ->get(route('games.show', $game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rematch.rematch_game_id', null)
            ->where('rematch.user_ids', []));

    $rematchId = $this->actingAs($ben)->postJson(route('games.rematch', $game))->json('game.id');

    $this->actingAs($ada)
        ->get(route('games.show', $game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rematch.rematch_game_id', $rematchId)
            ->where('rematch.user_ids', [$ben->id]));
});
