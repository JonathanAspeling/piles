<?php

use App\Enums\GameStatus;
use App\Events\GameLobbyUpdated;
use App\Events\LobbyUpdated;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function makeLobbyWithSeats(int $seats): GameSession
{
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'FULL10',
        'status' => GameStatus::Lobby,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    for ($i = 0; $i < $seats; $i++) {
        $game->gamePlayers()->create([
            'user_id' => $i === 0 ? $host->id : User::factory()->create()->id,
            'seat_index' => $i,
            'is_ready' => false,
        ]);
    }

    return $game;
}

test('a 10th player can join a lobby with 9 seats taken', function () {
    Event::fake([GameLobbyUpdated::class, LobbyUpdated::class]);

    $game = makeLobbyWithSeats(9);
    $joiner = User::factory()->create();

    $this->actingAs($joiner)
        ->postJson(route('games.join'), ['code' => $game->code])
        ->assertOk();

    expect($game->fresh()->gamePlayers()->count())->toBe(10);
});

test('an 11th player is rejected because the lobby is full', function () {
    Event::fake([GameLobbyUpdated::class, LobbyUpdated::class]);

    $game = makeLobbyWithSeats(10);
    $joiner = User::factory()->create();

    $this->actingAs($joiner)
        ->postJson(route('games.join'), ['code' => $game->code])
        ->assertStatus(422);

    expect($game->fresh()->gamePlayers()->count())->toBe(10);
});
