<?php

use App\Enums\GameStatus;
use App\Models\GameSession;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function makeLobbyGame(User $host, GameStatus $status): GameSession
{
    $game = GameSession::create([
        'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
        'status' => $status,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    return $game;
}

test('lobby surfaces an active game the current user is playing', function () {
    $user = User::factory()->create();
    $active = makeLobbyGame($user, GameStatus::Playing);

    $this->actingAs($user)
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Lobby/Index')
            ->where('activeGame.id', $active->id)
            ->where('activeGame.code', $active->code)
            ->where('activeGame.status', 'playing')
        );
});

test('lobby surfaces an active game while in countdown or verifying', function (GameStatus $status) {
    $user = User::factory()->create();
    $active = makeLobbyGame($user, $status);

    $this->actingAs($user)
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeGame.id', $active->id)
            ->where('activeGame.status', $status->value)
        );
})->with([
    'countdown' => [GameStatus::Countdown],
    'verifying' => [GameStatus::Verifying],
]);

test('lobby does not surface an active game the user is not a player in', function () {
    $user = User::factory()->create();
    $otherHost = User::factory()->create();
    makeLobbyGame($otherHost, GameStatus::Playing);

    $this->actingAs($user)
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activeGame', null));
});

test('lobby does not surface ended games as active', function () {
    $user = User::factory()->create();
    makeLobbyGame($user, GameStatus::Ended);

    $this->actingAs($user)
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activeGame', null));
});
