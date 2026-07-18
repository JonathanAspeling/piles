<?php

use App\Enums\GameStatus;
use App\Events\GameCountdownStarted;
use App\Jobs\StartGameJob;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function createCountdownGame(User $host, User $joiner): GameSession
{
    $game = GameSession::create([
        'code' => 'CNTDWN',
        'status' => GameStatus::Countdown,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => true,
        'is_game_ready' => false,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $joiner->id,
        'seat_index' => 1,
        'is_ready' => true,
        'is_game_ready' => false,
    ]);

    return $game;
}

test('client-ready holds off broadcasting the countdown until all clients confirm', function () {
    Event::fake([GameCountdownStarted::class]);
    Queue::fake();

    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $game = createCountdownGame($host, $joiner);

    $this->actingAs($host)
        ->post(route('games.client-ready', $game))
        ->assertNoContent();

    Event::assertNotDispatched(GameCountdownStarted::class);
    Queue::assertNotPushed(StartGameJob::class);
});

test('the final client-ready broadcasts GameCountdownStarted with duration_ms=3000 and queues StartGameJob with 3s delay', function () {
    Event::fake([GameCountdownStarted::class]);
    Queue::fake();

    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $game = createCountdownGame($host, $joiner);

    // First client acks — no broadcast yet
    $this->actingAs($host)->post(route('games.client-ready', $game));

    // Second client acks — countdown fires
    $this->actingAs($joiner)
        ->post(route('games.client-ready', $game))
        ->assertNoContent();

    Event::assertDispatched(GameCountdownStarted::class, function (GameCountdownStarted $event) use ($game) {
        return $event->game->id === $game->id && $event->durationMs === 3000;
    });

    Queue::assertPushed(StartGameJob::class, function (StartGameJob $job) use ($game) {
        return $job->game->id === $game->id
            && $job->delay !== null
            && $job->delay->between(now()->addSeconds(2), now()->addSeconds(4));
    });
});

test('client-ready is rejected when the game is not in countdown', function () {
    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $game = createCountdownGame($host, $joiner);
    $game->update(['status' => GameStatus::Playing]);

    $this->actingAs($host)
        ->post(route('games.client-ready', $game))
        ->assertStatus(422);
});
