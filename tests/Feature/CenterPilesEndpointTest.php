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

    // A player-owned pile — must NOT appear in the response.
    Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $gamePlayer->id,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);

    return $game;
}

test('returns center piles with version and top_card for a player in the game', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $response = $this->actingAs($player)
        ->get(route('gameplay.center-piles', $game))
        ->assertOk();

    $data = $response->json();

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

test('excludes player-owned piles from the response', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $data = $this->actingAs($player)
        ->get(route('gameplay.center-piles', $game))
        ->assertOk()
        ->json();

    // Only the two center piles, not the player-owned pile.
    expect($data)->toHaveCount(2);
    foreach ($data as $pile) {
        expect($pile['pile_index'])->toBeIn([0, 1]);
    }
});

test('a user not in the game gets a 404', function () {
    $player = User::factory()->create();
    $outsider = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $this->actingAs($outsider)
        ->get(route('gameplay.center-piles', $game))
        ->assertNotFound();
});

test('unauthenticated request is redirected', function () {
    $player = User::factory()->create();
    $game = seedGameWithCenterPiles($player);

    $this->get(route('gameplay.center-piles', $game))
        ->assertRedirect(route('login'));
});
