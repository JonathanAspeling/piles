<?php

use App\Enums\GameStatus;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;

test('pickup from a completed pile is rejected', function () {
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'PKUP01',
        'status' => GameStatus::Playing,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    $player = $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    $completedPile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $player->id,
        'pile_index' => 0,
        'is_completed' => true,
        'version' => 0,
    ]);

    $card = Card::firstOrCreate(['clothing_type' => 0, 'color' => 0]);
    PileCard::create([
        'pile_id' => $completedPile->id,
        'card_id' => $card->id,
        'position' => 0,
    ]);

    $this->actingAs($host)
        ->post(route('gameplay.pickup', $game), ['card_id' => $card->id])
        ->assertNotFound();

    $player->refresh();
    expect($player->picked_up_card_id)->toBeNull();
    expect($player->picked_up_pile_id)->toBeNull();
});
