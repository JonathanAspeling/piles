<?php

use App\Enums\GameStatus;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;

/**
 * A playing game where the user holds one pile of four different cards.
 *
 * @return array{0: GameSession, 1: GamePlayer, 2: Pile, 3: array<int, int>}
 */
function seedCardReorderGame(User $user): array
{
    $game = GameSession::create([
        'code' => 'CRORD1',
        'status' => GameStatus::Playing,
        'host_user_id' => $user->id,
        'variant' => false,
    ]);

    $player = $game->gamePlayers()->create([
        'user_id' => $user->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    $pile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $player->id,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);

    $cardIds = [];
    foreach (range(0, 3) as $position) {
        $cardIds[] = $cardId = Card::firstOrCreate(['clothing_type' => $position, 'color' => $position])->id;
        PileCard::create(['pile_id' => $pile->id, 'card_id' => $cardId, 'position' => $position]);
    }

    return [$game, $player, $pile, $cardIds];
}

/** @return array<int, int> card ids in position order */
function pileCardOrder(Pile $pile): array
{
    return $pile->pileCards()->pluck('card_id')->all();
}

test('a player can rearrange the cards in their pile', function () {
    $user = User::factory()->create();
    [$game, , $pile, $cardIds] = seedCardReorderGame($user);

    $rearranged = [$cardIds[2], $cardIds[0], $cardIds[3], $cardIds[1]];

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-pile-cards', [$game, $pile]), ['card_ids' => $rearranged])
        ->assertNoContent();

    expect(pileCardOrder($pile))->toBe($rearranged)
        ->and($pile->pileCards()->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

test('the rearranged order is what the board reads back', function () {
    $user = User::factory()->create();
    [$game, , $pile, $cardIds] = seedCardReorderGame($user);

    $rearranged = array_reverse($cardIds);

    $this->actingAs($user)->postJson(route('gameplay.reorder-pile-cards', [$game, $pile]), ['card_ids' => $rearranged]);

    $this->actingAs($user)
        ->getJson(route('gameplay.state', $game))
        ->assertOk()
        ->assertJsonPath('my_piles.0.cards.*.id', $rearranged);
});

test('a stale card list is a conflict and changes nothing', function () {
    $user = User::factory()->create();
    [$game, , $pile, $cardIds] = seedCardReorderGame($user);

    // A swap has since replaced the last card.
    $stale = [$cardIds[1], $cardIds[0], $cardIds[2], Card::firstOrCreate(['clothing_type' => 5, 'color' => 0])->id];

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-pile-cards', [$game, $pile]), ['card_ids' => $stale])
        ->assertConflict();

    expect(pileCardOrder($pile))->toBe($cardIds);
});

test('a player cannot rearrange someone elses pile', function () {
    $user = User::factory()->create();
    [$game, , $pile, $cardIds] = seedCardReorderGame($user);

    $rival = User::factory()->create();
    $game->gamePlayers()->create(['user_id' => $rival->id, 'seat_index' => 1, 'is_ready' => true]);

    $this->actingAs($rival)
        ->postJson(route('gameplay.reorder-pile-cards', [$game, $pile]), ['card_ids' => array_reverse($cardIds)])
        ->assertNotFound();

    expect(pileCardOrder($pile))->toBe($cardIds);
});

test('a completed pile cannot be rearranged', function () {
    $user = User::factory()->create();
    [$game, , $pile, $cardIds] = seedCardReorderGame($user);
    $pile->update(['is_completed' => true]);

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-pile-cards', [$game, $pile]), ['card_ids' => array_reverse($cardIds)])
        ->assertStatus(422);

    expect(pileCardOrder($pile))->toBe($cardIds);
});

test('cards cannot be rearranged outside of play', function () {
    $user = User::factory()->create();
    [$game, , $pile, $cardIds] = seedCardReorderGame($user);
    $game->update(['status' => GameStatus::Ended]);

    $this->actingAs($user)
        ->postJson(route('gameplay.reorder-pile-cards', [$game, $pile]), ['card_ids' => array_reverse($cardIds)])
        ->assertStatus(422);

    expect(pileCardOrder($pile))->toBe($cardIds);
});
