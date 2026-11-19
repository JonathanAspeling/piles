<?php

use App\Enums\GameStatus;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;

/**
 * @return array{0: GameSession, 1: GamePlayer, 2: Pile, 3: array<int, Card>}
 */
function seedPlayableGameWithPile(User $player): array
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

    $pile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $gamePlayer->id,
        'pile_index' => 0,
        'is_completed' => false,
        'version' => 0,
    ]);

    $cards = [];
    foreach (range(0, 1) as $position) {
        $card = Card::firstOrCreate(['clothing_type' => $position, 'color' => 0]);
        PileCard::create(['pile_id' => $pile->id, 'card_id' => $card->id, 'position' => $position]);
        $cards[] = $card;
    }

    return [$game, $gamePlayer, $pile, $cards];
}

test('releasing a picked-up card clears both pickup columns', function () {
    $user = User::factory()->create();
    [$game, $gamePlayer, $pile, $cards] = seedPlayableGameWithPile($user);

    $gamePlayer->update([
        'picked_up_card_id' => $cards[0]->id,
        'picked_up_pile_id' => $pile->id,
    ]);

    $this->actingAs($user)
        ->post(route('gameplay.release', $game))
        ->assertNoContent();

    $gamePlayer->refresh();
    expect($gamePlayer->picked_up_card_id)->toBeNull();
    expect($gamePlayer->picked_up_pile_id)->toBeNull();
});

test('releasing with nothing picked up is a harmless no-op', function () {
    $user = User::factory()->create();
    [$game, $gamePlayer] = seedPlayableGameWithPile($user);

    $this->actingAs($user)
        ->post(route('gameplay.release', $game))
        ->assertNoContent();

    $gamePlayer->refresh();
    expect($gamePlayer->picked_up_card_id)->toBeNull();
    expect($gamePlayer->picked_up_pile_id)->toBeNull();
});

test('releasing still succeeds once the game has ended', function () {
    $user = User::factory()->create();
    [$game, $gamePlayer, $pile, $cards] = seedPlayableGameWithPile($user);

    $gamePlayer->update([
        'picked_up_card_id' => $cards[0]->id,
        'picked_up_pile_id' => $pile->id,
    ]);
    $game->update(['status' => GameStatus::Ended]);

    $this->actingAs($user)
        ->post(route('gameplay.release', $game))
        ->assertNoContent();

    expect($gamePlayer->refresh()->picked_up_card_id)->toBeNull();
});

test('picking up a second card overwrites the first without a release', function () {
    $user = User::factory()->create();
    [$game, $gamePlayer, $pile, $cards] = seedPlayableGameWithPile($user);

    $this->actingAs($user)
        ->post(route('gameplay.pickup', $game), ['card_id' => $cards[0]->id])
        ->assertNoContent();

    expect($gamePlayer->refresh()->picked_up_card_id)->toBe($cards[0]->id);

    $this->actingAs($user)
        ->post(route('gameplay.pickup', $game), ['card_id' => $cards[1]->id])
        ->assertNoContent();

    $gamePlayer->refresh();
    expect($gamePlayer->picked_up_card_id)->toBe($cards[1]->id);
    expect($gamePlayer->picked_up_pile_id)->toBe($pile->id);
});

test('a user not in the game cannot release', function () {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    [$game] = seedPlayableGameWithPile($user);

    $this->actingAs($outsider)
        ->post(route('gameplay.release', $game))
        ->assertNotFound();
});

test('unauthenticated release is redirected', function () {
    $user = User::factory()->create();
    [$game] = seedPlayableGameWithPile($user);

    $this->post(route('gameplay.release', $game))
        ->assertRedirect(route('home'));
});
