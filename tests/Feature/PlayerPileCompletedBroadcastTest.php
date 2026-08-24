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

test('the completion broadcast reveals the finished set and nothing else', function () {
    $user = User::factory()->create();
    [$game, $player] = makeCompletionGame($user);
    $pile = makePile($game, $player, 2, 3, true);

    $payload = (new PlayerPileCompleted($game, $player, $pile))->broadcastWith();

    // Pinned: opponents learn which pile finished and what it holds, and the
    // rest of the owner's hand stays private.
    expect(array_keys($payload))->toBe(['game_player_id', 'pile_id', 'pile_index', 'cards']);

    expect($payload['game_player_id'])->toBe($player->id)
        ->and($payload['pile_id'])->toBe($pile->id)
        ->and($payload['pile_index'])->toBe(2)
        ->and($payload['cards'])->toHaveCount(4)
        ->and(array_column($payload['cards'], 'clothing_type'))->toBe([3, 3, 3, 3])
        ->and(array_column($payload['cards'], 'color'))->toBe([0, 1, 2, 3]);
});
