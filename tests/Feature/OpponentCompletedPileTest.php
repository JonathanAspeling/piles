<?php

use App\Enums\GameStatus;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * Fill a pile with four cards. A single clothing type makes it a completed set;
 * mixed types leave it in progress.
 *
 * @param  array<int, int>  $clothingTypes
 */
function fillPile(GamePlayer $owner, GameSession $game, int $pileIndex, array $clothingTypes, bool $isCompleted): Pile
{
    $pile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $owner->id,
        'pile_index' => $pileIndex,
        'is_completed' => $isCompleted,
        'version' => 0,
    ]);

    foreach ($clothingTypes as $position => $clothingType) {
        $card = Card::firstOrCreate(['clothing_type' => $clothingType, 'color' => $position]);
        PileCard::create(['pile_id' => $pile->id, 'card_id' => $card->id, 'position' => $position]);
    }

    return $pile;
}

test('a completed opponent pile reveals its clothing type, an in-progress one does not', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();

    $game = GameSession::create([
        'code' => 'OPPCMP',
        'status' => GameStatus::Playing,
        'host_user_id' => $me->id,
        'variant' => false,
    ]);

    $game->gamePlayers()->create(['user_id' => $me->id, 'seat_index' => 0, 'is_ready' => true]);
    $opponent = $game->gamePlayers()->create(['user_id' => $them->id, 'seat_index' => 1, 'is_ready' => true]);

    // Completed: four colours of clothing type 7.
    fillPile($opponent, $game, 0, [7, 7, 7, 7], isCompleted: true);
    // In progress: four different clothing types.
    fillPile($opponent, $game, 1, [11, 12, 13, 14], isCompleted: false);

    $this->actingAs($me)
        ->get(route('games.show', $game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('opponents.0.piles.0.is_completed', true)
            ->where('opponents.0.piles.0.completed_type', 7)
            ->where('opponents.0.piles.1.is_completed', false)
            ->where('opponents.0.piles.1.completed_type', null)
        );
});

test('an in-progress opponent pile leaks no card data at all', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();

    $game = GameSession::create([
        'code' => 'OPPLEK',
        'status' => GameStatus::Playing,
        'host_user_id' => $me->id,
        'variant' => false,
    ]);

    $game->gamePlayers()->create(['user_id' => $me->id, 'seat_index' => 0, 'is_ready' => true]);
    $opponent = $game->gamePlayers()->create(['user_id' => $them->id, 'seat_index' => 1, 'is_ready' => true]);

    fillPile($opponent, $game, 0, [21, 22, 23, 24], isCompleted: false);

    $response = $this->actingAs($me)->get(route('games.show', $game));

    $pile = $response->viewData('page')['props']['opponents'][0]['piles'][0];

    expect(array_keys($pile))->toBe(['id', 'pile_index', 'is_completed', 'completed_type']);
    expect($pile['completed_type'])->toBeNull();
});
