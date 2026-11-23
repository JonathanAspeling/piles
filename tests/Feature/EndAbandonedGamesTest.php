<?php

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\LobbyUpdated;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

function makeAbandonableGame(GameStatus $status, ?Carbon $updatedAt = null): GameSession
{
    $host = User::factory()->create();

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

    if ($updatedAt) {
        GameSession::withoutTimestamps(fn () => $game->forceFill(['updated_at' => $updatedAt])->save());
    }

    return $game;
}

test('ends games in playing/countdown/verifying that have been idle past the threshold', function (GameStatus $status) {
    Event::fake([GameEnded::class, LobbyUpdated::class]);

    $stale = makeAbandonableGame($status, now()->subMinutes(20));

    $this->artisan('games:end-abandoned')->assertSuccessful();

    $stale->refresh();
    expect($stale->status)->toBe(GameStatus::Ended);
    expect($stale->ended_at)->not->toBeNull();
    expect($stale->winner_user_id)->toBeNull();

    Event::assertDispatched(GameEnded::class);
    Event::assertDispatched(LobbyUpdated::class);
})->with([
    'playing' => [GameStatus::Playing],
    'countdown' => [GameStatus::Countdown],
    'verifying' => [GameStatus::Verifying],
]);

test('deletes lobby games that have been idle past the threshold', function () {
    Event::fake([LobbyUpdated::class]);

    $stale = makeAbandonableGame(GameStatus::Lobby, now()->subMinutes(20));

    $this->artisan('games:end-abandoned')->assertSuccessful();

    expect(GameSession::find($stale->id))->toBeNull();
    Event::assertDispatched(LobbyUpdated::class);
});

test('leaves fresh games alone', function () {
    Event::fake([GameEnded::class, LobbyUpdated::class]);

    $fresh = makeAbandonableGame(GameStatus::Playing, now()->subMinutes(5));
    $freshLobby = makeAbandonableGame(GameStatus::Lobby, now()->subMinutes(5));

    $this->artisan('games:end-abandoned')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(GameStatus::Playing);
    expect(GameSession::find($freshLobby->id))->not->toBeNull();

    Event::assertNotDispatched(GameEnded::class);
    Event::assertNotDispatched(LobbyUpdated::class);
});

test('leaves already-ended games alone', function () {
    Event::fake([GameEnded::class, LobbyUpdated::class]);

    makeAbandonableGame(GameStatus::Ended, now()->subMinutes(60));

    $this->artisan('games:end-abandoned')->assertSuccessful();

    Event::assertNotDispatched(GameEnded::class);
    Event::assertNotDispatched(LobbyUpdated::class);
});

test('respects a custom minutes threshold', function () {
    Event::fake([GameEnded::class, LobbyUpdated::class]);

    $game = makeAbandonableGame(GameStatus::Playing, now()->subMinutes(3));

    $this->artisan('games:end-abandoned', ['--minutes' => 2])->assertSuccessful();

    expect($game->fresh()->status)->toBe(GameStatus::Ended);
    Event::assertDispatched(GameEnded::class);
});
