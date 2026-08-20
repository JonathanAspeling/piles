<?php

use App\Enums\GameStatus;
use App\Events\PlayerPileCompleted;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * A one-player game with a single four-card pile and a single centre pile.
 *
 * @param  array<int, array{0: int, 1: int}>  $pileCards  clothing type + colour per slot
 * @param  array{0: int, 1: int}  $centerCardSpec  clothing type + colour of the centre card
 * @return array{0: GameSession, 1: GamePlayer, 2: Pile, 3: Pile, 4: Card}
 */
function seedSwapGame(User $user, array $pileCards, array $centerCardSpec): array
{
    $game = GameSession::create([
        'code' => 'SWAP01',
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

    foreach ($pileCards as $position => [$clothingType, $color]) {
        PileCard::create([
            'pile_id' => $pile->id,
            'card_id' => Card::firstOrCreate(['clothing_type' => $clothingType, 'color' => $color])->id,
            'position' => $position,
        ]);
    }

    $centerPile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => null,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);

    $centerCard = Card::firstOrCreate(['clothing_type' => $centerCardSpec[0], 'color' => $centerCardSpec[1]]);
    PileCard::create(['pile_id' => $centerPile->id, 'card_id' => $centerCard->id, 'position' => 0]);

    return [$game, $player, $pile, $centerPile, $centerCard];
}

function cardIdAt(Pile $pile, int $position): int
{
    return $pile->pileCards()->where('position', $position)->firstOrFail()->card_id;
}

test('a valid swap exchanges the two cards and bumps the centre pile version', function () {
    $user = User::factory()->create();
    [$game, $player, $pile, $centerPile, $centerCard] = seedSwapGame(
        $user,
        [[0, 0], [1, 1], [2, 2], [3, 3]],
        [40, 0],
    );

    $myCardId = cardIdAt($pile, 3);
    $player->update(['picked_up_card_id' => $myCardId, 'picked_up_pile_id' => $pile->id]);

    $this->actingAs($user)
        ->postJson(route('gameplay.swap', $game), [
            'center_pile_id' => $centerPile->id,
            'center_card_id' => $centerCard->id,
            'expected_version' => 0,
        ])
        ->assertOk();

    expect(cardIdAt($pile, 3))->toBe($centerCard->id)
        ->and(cardIdAt($centerPile, 0))->toBe($myCardId)
        ->and($centerPile->fresh()->version)->toBe(1);

    $player->refresh();
    expect($player->picked_up_card_id)->toBeNull()
        ->and($player->picked_up_pile_id)->toBeNull();
});

test('a stale expected version is rejected with 409 and leaves the pickup intact', function () {
    $user = User::factory()->create();
    [$game, $player, $pile, $centerPile, $centerCard] = seedSwapGame(
        $user,
        [[0, 0], [1, 1], [2, 2], [3, 3]],
        [40, 0],
    );

    $myCardId = cardIdAt($pile, 3);
    $player->update(['picked_up_card_id' => $myCardId, 'picked_up_pile_id' => $pile->id]);

    $this->actingAs($user)
        ->postJson(route('gameplay.swap', $game), [
            'center_pile_id' => $centerPile->id,
            'center_card_id' => $centerCard->id,
            'expected_version' => 7,
        ])
        ->assertStatus(409);

    // Nothing moved, and the pickup survives — the client's rollback and retry
    // both depend on the card still being in hand after a lost race.
    expect(cardIdAt($pile, 3))->toBe($myCardId)
        ->and(cardIdAt($centerPile, 0))->toBe($centerCard->id)
        ->and($centerPile->fresh()->version)->toBe(0)
        ->and($player->fresh()->picked_up_card_id)->toBe($myCardId);
});

test('a swap without a pickup is rejected', function () {
    $user = User::factory()->create();
    [$game, , , $centerPile, $centerCard] = seedSwapGame(
        $user,
        [[0, 0], [1, 1], [2, 2], [3, 3]],
        [40, 0],
    );

    $this->actingAs($user)
        ->postJson(route('gameplay.swap', $game), [
            'center_pile_id' => $centerPile->id,
            'center_card_id' => $centerCard->id,
            'expected_version' => 0,
        ])
        ->assertStatus(422);
});

test('a swap is rejected when the game is not in progress', function () {
    $user = User::factory()->create();
    [$game, $player, $pile, $centerPile, $centerCard] = seedSwapGame(
        $user,
        [[0, 0], [1, 1], [2, 2], [3, 3]],
        [40, 0],
    );

    $player->update(['picked_up_card_id' => cardIdAt($pile, 3), 'picked_up_pile_id' => $pile->id]);
    $game->update(['status' => GameStatus::Ended]);

    $this->actingAs($user)
        ->postJson(route('gameplay.swap', $game), [
            'center_pile_id' => $centerPile->id,
            'center_card_id' => $centerCard->id,
            'expected_version' => 0,
        ])
        ->assertStatus(422);
});

test('a swap that completes a pile marks it completed and dispatches PlayerPileCompleted', function () {
    Event::fake([PlayerPileCompleted::class]);

    $user = User::factory()->create();
    [$game, $player, $pile, $centerPile, $centerCard] = seedSwapGame(
        $user,
        [[0, 0], [0, 1], [0, 2], [7, 3]],
        [0, 3],
    );

    $player->update(['picked_up_card_id' => cardIdAt($pile, 3), 'picked_up_pile_id' => $pile->id]);

    $this->actingAs($user)
        ->postJson(route('gameplay.swap', $game), [
            'center_pile_id' => $centerPile->id,
            'center_card_id' => $centerCard->id,
            'expected_version' => 0,
        ])
        ->assertOk();

    expect($pile->fresh()->is_completed)->toBeTrue();

    Event::assertDispatched(
        PlayerPileCompleted::class,
        fn (PlayerPileCompleted $event) => $event->pile->id === $pile->id && $event->player->id === $player->id,
    );
});

test('a swap that leaves the pile mixed dispatches nothing', function () {
    Event::fake([PlayerPileCompleted::class]);

    $user = User::factory()->create();
    [$game, $player, $pile, $centerPile, $centerCard] = seedSwapGame(
        $user,
        [[0, 0], [0, 1], [2, 2], [7, 3]],
        [5, 3],
    );

    $player->update(['picked_up_card_id' => cardIdAt($pile, 3), 'picked_up_pile_id' => $pile->id]);

    $this->actingAs($user)
        ->postJson(route('gameplay.swap', $game), [
            'center_pile_id' => $centerPile->id,
            'center_card_id' => $centerCard->id,
            'expected_version' => 0,
        ])
        ->assertOk();

    expect($pile->fresh()->is_completed)->toBeFalse();

    Event::assertNotDispatched(PlayerPileCompleted::class);
});
