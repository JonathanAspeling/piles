<?php

use App\Enums\GameStatus;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;

function seedGameWithCenterPiles(User $player): GameSession
{
    $game = GameSession::create([
        'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
        'status' => GameStatus::Playing,
        'host_user_id' => $player->id,
        'variant' => false,
    ]);

    $gamePlayer = $game->gamePlayers()->create([
        'user_id' => $player->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    // Two center piles (game_player_id null), one with a top card, one empty.
    $centerA = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => null,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 3,
    ]);
    $card = Card::firstOrCreate(['clothing_type' => 0, 'color' => 0]);
    PileCard::create(['pile_id' => $centerA->id, 'card_id' => $card->id, 'position' => 0]);

    Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => null,
        'pile_index' => 1,
        'is_completed' => false,
        'version' => 0,
    ]);

    // A player-owned pile — must NOT appear among the center piles.
    $ownPile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $gamePlayer->id,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);
    PileCard::create([
        'pile_id' => $ownPile->id,
        'card_id' => Card::firstOrCreate(['clothing_type' => 5, 'color' => 2])->id,
        'position' => 0,
    ]);

    return $game;
}

function seedOpponentWithPile(GameSession $game): Pile
{
    $opponent = $game->gamePlayers()->create([
        'user_id' => User::factory()->create()->id,
        'seat_index' => 1,
        'is_ready' => true,
    ]);

    $pile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $opponent->id,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);
    PileCard::create([
        'pile_id' => $pile->id,
        'card_id' => Card::firstOrCreate(['clothing_type' => 9, 'color' => 3])->id,
        'position' => 0,
    ]);

    return $pile;
}

test('returns center piles with version and top_card for a player in the game', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $data = $this->actingAs($player)
        ->get(route('gameplay.state', $game))
        ->assertOk()
        ->json('center_piles');

    expect($data)->toHaveCount(2);
    expect($data[0])->toMatchArray([
        'pile_index' => 0,
        'version' => 3,
    ]);
    expect($data[0]['top_card'])->toMatchArray([
        'clothing_type' => 0,
        'color' => 0,
    ]);
    expect($data[1])->toMatchArray([
        'pile_index' => 1,
        'version' => 0,
        'top_card' => null,
    ]);
});

test('excludes player-owned piles from the center piles', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $data = $this->actingAs($player)
        ->get(route('gameplay.state', $game))
        ->assertOk()
        ->json('center_piles');

    // Only the two center piles, not the player-owned pile.
    expect($data)->toHaveCount(2);
    foreach ($data as $pile) {
        expect($pile['pile_index'])->toBeIn([0, 1]);
    }
});

test('returns the players own piles with their cards', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $data = $this->actingAs($player)
        ->get(route('gameplay.state', $game))
        ->assertOk()
        ->json('my_piles');

    expect($data)->toHaveCount(1);
    expect(array_keys($data[0]))->toBe(['id', 'pile_index', 'is_completed', 'cards']);
    expect($data[0])->toMatchArray([
        'pile_index' => 0,
        'is_completed' => false,
    ]);
    expect($data[0]['cards'][0])->toMatchArray([
        'clothing_type' => 5,
        'color' => 2,
    ]);
});

test('never returns another players piles', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);
    $opponentPile = seedOpponentWithPile($game);

    $data = $this->actingAs($player)
        ->get(route('gameplay.state', $game))
        ->assertOk()
        ->json('my_piles');

    expect(array_column($data, 'id'))->not->toContain($opponentPile->id);
});

test('a user not in the game gets a 404', function () {
    $player = User::factory()->create();
    $outsider = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $this->actingAs($outsider)
        ->get(route('gameplay.state', $game))
        ->assertNotFound();
});

test('unauthenticated request is redirected', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $this->get(route('gameplay.state', $game))
        ->assertRedirect(route('login'));
});
