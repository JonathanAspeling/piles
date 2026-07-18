<?php

use App\Enums\GameStatus;
use App\Models\GameSession;
use App\Models\User;

function makeStatusGame(User $host, GameStatus $status, ?int $winnerUserId = null): GameSession
{
    $game = GameSession::create([
        'code' => 'STATUS',
        'status' => $status,
        'host_user_id' => $host->id,
        'variant' => false,
        'winner_user_id' => $winnerUserId,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    return $game;
}

test('games.status returns the current status with a null winner while Playing', function () {
    $host = User::factory()->create();
    $game = makeStatusGame($host, GameStatus::Playing);

    $this->actingAs($host)
        ->get(route('games.status', $game))
        ->assertOk()
        ->assertExactJson([
            'status' => 'playing',
            'winner' => null,
        ]);
});

test('games.status returns a populated winner when the game has ended with a winner', function () {
    $host = User::factory()->create(['name' => 'Winston']);
    $game = makeStatusGame($host, GameStatus::Ended, winnerUserId: $host->id);
    $hostPlayer = $game->gamePlayers()->first();

    $this->actingAs($host)
        ->get(route('games.status', $game))
        ->assertOk()
        ->assertExactJson([
            'status' => 'ended',
            'winner' => [
                'game_player_id' => $hostPlayer->id,
                'user_id' => $host->id,
                'name' => 'Winston',
            ],
        ]);
});

test('games.status returns null winner when the game ended without one (forfeit)', function () {
    $host = User::factory()->create();
    $game = makeStatusGame($host, GameStatus::Ended);

    $this->actingAs($host)
        ->get(route('games.status', $game))
        ->assertOk()
        ->assertExactJson([
            'status' => 'ended',
            'winner' => null,
        ]);
});

test('games.status requires authentication', function () {
    $host = User::factory()->create();
    $game = makeStatusGame($host, GameStatus::Playing);

    $this->get(route('games.status', $game))->assertRedirect(route('login'));
});
