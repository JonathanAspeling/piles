<?php

use App\Enums\GameStatus;
use App\Events\PlayerPileCompleted;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;

function makeCompletionGame(User $user): array
{
    $game = GameSession::create([
        'code' => 'PILDON',
        'status' => GameStatus::Playing,
        'host_user_id' => $user->id,
        'variant' => false,
    ]);

    $player = $game->gamePlayers()->create([
        'user_id' => $user->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    return [$game, $player];
}

function makePile(GameSession $game, GamePlayer $player, int $pileIndex, int $clothingType, bool $completed): Pile
{
    $pile = Pile::create([
        'game_session_id' => $game->id,
        'game_player_id' => $player->id,
        'pile_index' => $pileIndex,
        'is_completed' => $completed,
        'version' => 0,
    ]);

    for ($color = 0; $color < 4; $color++) {
        PileCard::create([
            'pile_id' => $pile->id,
            'card_id' => Card::firstOrCreate(['clothing_type' => $clothingType, 'color' => $color])->id,
            'position' => $color,
        ]);
    }

    return $pile;
}

test('the completion broadcast carries the announcing fields', function () {
    $user = User::factory()->create(['name' => 'Nick']);
    [$game, $player] = makeCompletionGame($user);
    $pile = makePile($game, $player, 0, 3, true);

    $payload = (new PlayerPileCompleted($game, $player, $pile))->broadcastWith();

    expect(array_keys($payload))->toBe([
        'game_player_id',
        'pile_id',
        'pile_index',
        'cards',
        'player_name',
        'completed_count',
    ]);

    expect($payload['player_name'])->toBe('Nick')
        ->and($payload['completed_count'])->toBe(1)
        ->and($payload['cards'])->toHaveCount(4);
});

test('completed_count counts only that players completed piles', function () {
    $user = User::factory()->create();
    [$game, $player] = makeCompletionGame($user);

    $pile = makePile($game, $player, 0, 3, true);
    makePile($game, $player, 1, 4, true);
    makePile($game, $player, 2, 5, false);
    makePile($game, $player, 3, 6, false);
    makePile($game, $player, 4, 7, false);

    // A rival's finished pile must not inflate the count.
    $rival = $game->gamePlayers()->create([
        'user_id' => User::factory()->create()->id,
        'seat_index' => 1,
        'is_ready' => true,
    ]);
    makePile($game, $rival, 0, 8, true);

    $payload = (new PlayerPileCompleted($game, $player, $pile))->broadcastWith();

    expect($payload['completed_count'])->toBe(2);
});
