<?php

use App\Enums\GameStatus;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A playing game where the user holds six empty piles, indexed 0-5.
 *
 * @return array{0: GameSession, 1: GamePlayer, 2: Collection<int, Pile>}
 */
function seedReorderGame(User $user): array
{
    $game = GameSession::create([
        'code' => 'RORDR1',
        'status' => GameStatus::Playing,
        'host_user_id' => $user->id,
        'variant' => false,
    ]);

    $player = $game->gamePlayers()->create([
        'user_id' => $user->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    $piles = collect(range(0, 5))->map(fn (int $pileIndex) => Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $player->id,
        'pile_index' => $pileIndex,
        'is_completed' => false,
        'version' => 0,
    ]));

    return [$game, $player, $piles];
}

/** @return array<int, int> pile ids in pile_index order */
function currentOrder(GamePlayer $player): array
{
    return $player->piles()->pluck('id')->all();
}

test('a player can reorder their own piles', function () {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);

    $shuffled = [$piles[3]->id, $piles[0]->id, $piles[5]->id, $piles[1]->id, $piles[4]->id, $piles[2]->id];

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => $shuffled])
        ->assertNoContent();

    expect(currentOrder($player))->toBe($shuffled);
});

test('submitting the current order is a no-op', function () {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);

    $original = $piles->pluck('id')->all();

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => $original])
        ->assertNoContent();

    expect(currentOrder($player))->toBe($original);
});

test('a completed pile can be moved', function () {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);

    $piles[4]->update(['is_completed' => true]);

    $moved = [$piles[4]->id, $piles[0]->id, $piles[1]->id, $piles[2]->id, $piles[3]->id, $piles[5]->id];

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => $moved])
        ->assertNoContent();

    expect(currentOrder($player))->toBe($moved)
        ->and($piles[4]->fresh()->pile_index)->toBe(0);
});

test('reordering with another players pile is rejected', function () {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);
    $original = $piles->pluck('id')->all();

    $rival = $game->gamePlayers()->create([
        'user_id' => User::factory()->create()->id,
        'seat_index' => 1,
        'is_ready' => true,
    ]);
    $rivalPile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $rival->id,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);

    $withRival = array_slice($original, 0, 5);
    $withRival[] = $rivalPile->id;

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => $withRival])
        ->assertStatus(422);

    expect(currentOrder($player))->toBe($original)
        ->and($rivalPile->fresh()->pile_index)->toBe(0);
});

test('reordering with a duplicate pile id is rejected', function () {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);
    $original = $piles->pluck('id')->all();

    $duplicated = $original;
    $duplicated[5] = $duplicated[0];

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => $duplicated])
        ->assertStatus(422);

    expect(currentOrder($player))->toBe($original);
});

test('reordering with the wrong number of piles is rejected', function () {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);
    $original = $piles->pluck('id')->all();

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => array_slice($original, 0, 3)])
        ->assertStatus(422);

    expect(currentOrder($player))->toBe($original);
});

test('reordering is rejected when the game is not in progress', function (GameStatus $status) {
    $user = User::factory()->create();
    [$game, $player, $piles] = seedReorderGame($user);
    $original = $piles->pluck('id')->all();

    $game->update(['status' => $status]);

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => array_reverse($original)])
        ->assertStatus(422);

    expect(currentOrder($player))->toBe($original);
})->with([GameStatus::Lobby, GameStatus::Ended]);

test('a user not in the game gets a 404', function () {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    [$game, , $piles] = seedReorderGame($user);

    $this->actingAs($outsider)
        ->postJson(route('gameplay.reorder-piles', $game), ['pile_ids' => $piles->pluck('id')->all()])
        ->assertNotFound();
});

test('unauthenticated request is redirected', function () {
    $user = User::factory()->create();
    [$game, , $piles] = seedReorderGame($user);

    $this->post(route('gameplay.reorder-piles', $game), ['pile_ids' => $piles->pluck('id')->all()])
        ->assertRedirect(route('login'));
});
