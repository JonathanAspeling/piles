<?php

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\LobbyUpdated;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function createActiveGame(User $host, User $joiner): GameSession
{
    $game = GameSession::create([
        'code' => 'TESTGM',
        'status' => GameStatus::Playing,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $joiner->id,
        'seat_index' => 1,
        'is_ready' => true,
    ]);

    return $game;
}

test('a player can forfeit an active game', function () {
    Event::fake([GameEnded::class, LobbyUpdated::class]);

    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $game = createActiveGame($host, $joiner);

    $this->actingAs($joiner)
        ->post(route('gameplay.forfeit', $game))
        ->assertNoContent();

    $game->refresh();
    expect($game->status)->toBe(GameStatus::Ended);
    expect($game->winner_user_id)->toBeNull();
    expect($game->ended_at)->not->toBeNull();

    Event::assertDispatched(GameEnded::class);
    Event::assertDispatched(LobbyUpdated::class);
});

test('forfeited GameEnded event carries forfeited_by and null winner', function () {
    Event::fake([GameEnded::class]);

    $host = User::factory()->create();
    $joiner = User::factory()->create(['name' => 'Alice']);
    $game = createActiveGame($host, $joiner);

    $this->actingAs($joiner)->post(route('gameplay.forfeit', $game));

    Event::assertDispatched(GameEnded::class, function (GameEnded $event) use ($joiner) {
        return $event->winner === null && $event->forfeitedBy === $joiner->name;
    });
});

test('cannot forfeit a game in the lobby', function () {
    $host = User::factory()->create();
    $game = GameSession::create([
        'code' => 'LOBBY1',
        'status' => GameStatus::Lobby,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);
    $game->gamePlayers()->create(['user_id' => $host->id, 'seat_index' => 0, 'is_ready' => false]);

    $this->actingAs($host)
        ->post(route('gameplay.forfeit', $game))
        ->assertStatus(422);
});

test('cannot forfeit a game you are not in', function () {
    $host = User::factory()->create();
    $outsider = User::factory()->create();
    $joiner = User::factory()->create();
    $game = createActiveGame($host, $joiner);

    $this->actingAs($outsider)
        ->post(route('gameplay.forfeit', $game))
        ->assertStatus(404);
});

test('can forfeit a game that is in the verifying state', function () {
    Event::fake([GameEnded::class, LobbyUpdated::class]);

    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $game = createActiveGame($host, $joiner);
    $game->update(['status' => GameStatus::Verifying]);

    $this->actingAs($host)
        ->post(route('gameplay.forfeit', $game))
        ->assertNoContent();

    expect($game->fresh()->status)->toBe(GameStatus::Ended);
});
