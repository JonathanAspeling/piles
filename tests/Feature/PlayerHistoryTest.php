<?php

use App\Enums\GameStatus;
use App\Jobs\StartGameJob;
use App\Models\GameSession;
use App\Models\User;
use App\Services\PlayerHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A finished game between the given players.
 *
 * @param  list<User>  $players
 */
function finishedGame(array $players, ?User $winner, ?int $seconds = 120, string $endedAt = '2026-11-20 20:00:00', array $attributes = []): GameSession
{
    $ended = Carbon::parse($endedAt);

    $game = GameSession::create([
        'code' => strtoupper(Str::random(6)),
        'status' => GameStatus::Ended,
        'host_user_id' => $players[0]->id,
        'variant' => false,
        'winner_user_id' => $winner?->id,
        'started_at' => $seconds === null ? $ended : $ended->copy()->subSeconds($seconds),
        'ended_at' => $seconds === null ? null : $ended,
        ...$attributes,
    ]);

    foreach ($players as $seat => $player) {
        $game->gamePlayers()->create(['user_id' => $player->id, 'seat_index' => $seat, 'is_ready' => true]);
    }

    return $game;
}

beforeEach(function () {
    $this->me = User::factory()->create(['name' => 'Jonathan']);
    $this->cheron = User::factory()->create(['name' => 'Cheron']);
    $this->nik = User::factory()->create(['name' => 'Nik']);
    $this->history = new PlayerHistory;
});

test('a new player has an empty record', function () {
    expect($this->history->for($this->me))->toBe([
        'played' => 0,
        'won' => 0,
        'win_streak' => 0,
        'fastest_win_seconds' => null,
        'rivals' => [],
        'recent' => [],
    ]);
});

test('only finished games that got going, and that you were in, count', function () {
    finishedGame([$this->me, $this->cheron], $this->me);
    finishedGame([$this->cheron, $this->nik], $this->nik);

    // Ended in its lobby: never played.
    GameSession::create(['code' => 'LOBBY1', 'status' => GameStatus::Ended, 'host_user_id' => $this->me->id, 'variant' => false])
        ->gamePlayers()->create(['user_id' => $this->me->id, 'seat_index' => 0, 'is_ready' => false]);

    // Still being played.
    finishedGame([$this->me, $this->nik], null, attributes: ['status' => GameStatus::Playing, 'ended_at' => null]);

    $record = $this->history->for($this->me);

    expect($record['played'])->toBe(1)
        ->and($record['won'])->toBe(1);
});

test('wins, the current streak and the fastest win', function () {
    finishedGame([$this->me, $this->cheron], $this->me, seconds: 300, endedAt: '2026-11-18 20:00');
    finishedGame([$this->me, $this->cheron], $this->cheron, seconds: 45, endedAt: '2026-11-19 20:00');
    finishedGame([$this->me, $this->cheron], $this->me, seconds: 95, endedAt: '2026-11-20 20:00');
    finishedGame([$this->me, $this->cheron], $this->me, seconds: null, endedAt: '2026-11-21 20:00');
    finishedGame([$this->me, $this->cheron], $this->me, seconds: 130, endedAt: '2026-11-22 20:00');

    $record = $this->history->for($this->me);

    // The 45s game was a loss, and a win without a finish time can't be timed.
    expect($record['played'])->toBe(5)
        ->and($record['won'])->toBe(4)
        ->and($record['fastest_win_seconds'])->toBe(95);
});

test('the win streak counts back from the latest game and stops at the first non-win', function () {
    finishedGame([$this->me, $this->cheron], $this->me, endedAt: '2026-11-18 20:00');
    finishedGame([$this->me, $this->cheron], null, endedAt: '2026-11-19 20:00');
    finishedGame([$this->me, $this->cheron], $this->me, endedAt: '2026-11-20 20:00');
    finishedGame([$this->me, $this->cheron], $this->me, endedAt: '2026-11-21 20:00');

    expect($this->history->for($this->me)['win_streak'])->toBe(2);
    expect($this->history->for($this->cheron)['win_streak'])->toBe(0);
});

test('the people you play most, with how your games together went', function () {
    finishedGame([$this->me, $this->cheron], $this->me);
    finishedGame([$this->me, $this->cheron], $this->cheron);
    finishedGame([$this->me, $this->cheron, $this->nik], $this->me);
    finishedGame([$this->me, $this->cheron, $this->nik], $this->nik);

    expect($this->history->for($this->me)['rivals'])->toBe([
        ['name' => 'Cheron', 'games' => 4, 'my_wins' => 2, 'their_wins' => 1],
        ['name' => 'Nik', 'games' => 2, 'my_wins' => 1, 'their_wins' => 1],
    ]);
});

test('only your top three rivals are listed', function () {
    $others = User::factory()->count(4)->create();
    foreach ($others as $index => $other) {
        for ($game = 0; $game <= $index; $game++) {
            finishedGame([$this->me, $other], $this->me);
        }
    }

    expect(collect($this->history->for($this->me)['rivals'])->pluck('games')->all())->toBe([4, 3, 2]);
});

test('recent games are newest first, from your side of the table', function () {
    finishedGame([$this->me, $this->cheron], $this->me, seconds: 90, endedAt: '2026-11-19 20:00');
    finishedGame([$this->nik, $this->me, $this->cheron], $this->nik, seconds: 200, endedAt: '2026-11-20 20:00', attributes: ['variant' => true, 'similar_items' => true]);
    finishedGame([$this->me, $this->cheron], null, endedAt: '2026-11-21 20:00');

    $recent = $this->history->for($this->me)['recent'];

    expect(collect($recent)->pluck('result')->all())->toBe(['no_winner', 'lost', 'won'])
        ->and($recent[1])->toMatchArray([
            'winner_name' => 'Nik',
            'opponents' => ['Nik', 'Cheron'],
            'duration_seconds' => 200,
            'variant' => true,
            'similar_items' => true,
        ])
        ->and($recent[2]['opponents'])->toBe(['Cheron'])
        ->and($recent[2]['ended_at'])->toBe(Carbon::parse('2026-11-19 20:00')->toIso8601String());
});

test('recent games stop at ten', function () {
    for ($day = 1; $day <= 12; $day++) {
        finishedGame([$this->me, $this->cheron], $this->me, endedAt: "2026-11-{$day} 20:00");
    }

    $record = $this->history->for($this->me);

    expect($record['played'])->toBe(12)
        ->and($record['recent'])->toHaveCount(PlayerHistory::RECENT_GAMES)
        ->and($record['recent'][0]['ended_at'])->toBe(Carbon::parse('2026-11-12 20:00')->toIso8601String());
});

test('the lobby loads your record after it renders', function () {
    finishedGame([$this->me, $this->cheron], $this->me, seconds: 75);

    $this->actingAs($this->me)
        ->get(route('lobby.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lobby/Index')
            ->missing('history')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('history.played', 1)
                ->where('history.won', 1)
                ->where('history.fastest_win_seconds', 75)
                ->where('history.recent.0.opponents', ['Cheron'])));
});

test('play time starts at GO, not at the deal', function () {
    Event::fake();
    $game = finishedGame([$this->me, $this->cheron], null, attributes: [
        'status' => GameStatus::Countdown,
        'started_at' => now()->subMinute(),
        'ended_at' => null,
    ]);

    $this->freezeSecond();
    (new StartGameJob($game))->handle();

    expect($game->fresh()->started_at->equalTo(now()))->toBeTrue();
});
