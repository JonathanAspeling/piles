<?php

use App\Enums\CardTheme;
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

function makeThemedLobbyGame(User $host, CardTheme $theme, bool $hardVariant): GameSession
{
    $game = GameSession::create([
        'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
        'status' => GameStatus::Lobby,
        'host_user_id' => $host->id,
        'variant' => false,
        'theme' => $theme,
        'hard_variant' => $hardVariant,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => false,
    ]);

    return $game;
}

test('a new game defaults to the standard theme with hard colour variant off', function () {
    $host = User::factory()->create();

    $this->actingAs($host)
        ->postJson(route('games.store'), ['variant' => false])
        ->assertOk()
        ->assertJsonPath('game.theme', 'standard')
        ->assertJsonPath('game.hard_variant', false);

    $game = GameSession::sole();

    expect($game->theme)->toBe(CardTheme::Standard)
        ->and($game->hard_variant)->toBeFalse();
});

test('a game created directly falls back to the column defaults', function () {
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'DEFLT1',
        'status' => GameStatus::Lobby,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    expect($game->theme)->toBe(CardTheme::Standard)
        ->and($game->hard_variant)->toBeFalse()
        ->and($game->fresh()->theme)->toBe(CardTheme::Standard)
        ->and($game->fresh()->hard_variant)->toBeFalse();
});

test('the host picks the theme and hard colour variant when creating the game', function (CardTheme $theme, bool $hardVariant) {
    $host = User::factory()->create();

    $this->actingAs($host)
        ->postJson(route('games.store'), [
            'variant' => false,
            'theme' => $theme->value,
            'hard_variant' => $hardVariant,
        ])
        ->assertOk()
        ->assertJsonPath('game.theme', $theme->value)
        ->assertJsonPath('game.hard_variant', $hardVariant);

    $game = GameSession::sole();

    expect($game->theme)->toBe($theme)
        ->and($game->hard_variant)->toBe($hardVariant);
})->with([
    'classic' => [CardTheme::Classic, false],
    'standard' => [CardTheme::Standard, false],
    'standard, hard colours' => [CardTheme::Standard, true],
]);

test('an unknown theme is rejected', function (mixed $theme) {
    $this->actingAs(User::factory()->create())
        ->postJson(route('games.store'), ['theme' => $theme])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('theme');

    expect(GameSession::count())->toBe(0);
})->with(['neon', 'Standard', '', 1]);

test('a non-boolean hard colour variant is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('games.store'), ['hard_variant' => 'sometimes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hard_variant');
});

test('a joining player receives the game theme and hard colour variant', function () {
    $game = makeThemedLobbyGame(User::factory()->create(), CardTheme::Classic, true);

    $this->actingAs(User::factory()->create())
        ->postJson(route('games.join'), ['code' => $game->code])
        ->assertOk()
        ->assertJsonPath('game.theme', 'classic')
        ->assertJsonPath('game.hard_variant', true);
});

test('the game page sends the theme and hard colour variant to every player', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $game = makeThemedLobbyGame($host, CardTheme::Standard, true);
    $game->gamePlayers()->create(['user_id' => $guest->id, 'seat_index' => 1, 'is_ready' => false]);

    foreach ([$host, $guest] as $player) {
        $this->actingAs($player)
            ->get(route('games.show', $game))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Game/Show')
                ->where('game.theme', 'standard')
                ->where('game.hard_variant', true)
            );
    }
});

test('the open games list shows each game theme and hard colour variant', function () {
    $game = makeThemedLobbyGame(User::factory()->create(), CardTheme::Classic, false);

    $this->actingAs(User::factory()->create())
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.0.id', $game->id)
            ->where('games.0.theme', 'classic')
            ->where('games.0.hard_variant', false)
        );

    expect((new LobbyUpdated)->broadcastWith()['games'][0])
        ->toMatchArray(['id' => $game->id, 'theme' => 'classic', 'hard_variant' => false]);
});

test('every new game gets its own random background seed', function () {
    $host = User::factory()->create();

    $first = $this->actingAs($host)->postJson(route('games.store'), ['hard_variant' => true])->assertOk();
    $second = $this->actingAs($host)->postJson(route('games.store'), ['hard_variant' => true])->assertOk();

    $seeds = GameSession::orderBy('id')->pluck('background_seed')->all();

    expect($seeds)->toHaveCount(2)
        ->and($seeds[0])->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(0x7FFFFFFF)
        ->and($seeds[1])->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(0x7FFFFFFF)
        ->and($seeds[0])->not->toBe($seeds[1])
        ->and($first->json('game.background_seed'))->toBe($seeds[0])
        ->and($second->json('game.background_seed'))->toBe($seeds[1]);
});

test('a game keeps its background seed for its whole life', function () {
    $game = makeThemedLobbyGame(User::factory()->create(), CardTheme::Standard, true);
    $seed = $game->background_seed;

    $game->update(['status' => GameStatus::Playing]);
    $game->update(['status' => GameStatus::Ended]);

    expect($seed)->toBeGreaterThan(0)
        ->and($game->fresh()->background_seed)->toBe($seed);
});

test('every player in a game receives the same background seed', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $game = makeThemedLobbyGame($host, CardTheme::Standard, true);

    $this->actingAs($guest)
        ->postJson(route('games.join'), ['code' => $game->code])
        ->assertOk()
        ->assertJsonPath('game.background_seed', $game->background_seed);

    foreach ([$host, $guest] as $player) {
        $this->actingAs($player)
            ->get(route('games.show', $game))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('game.background_seed', $game->background_seed)
            );
    }
});
