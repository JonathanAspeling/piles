<?php

use App\Enums\ClothingType;
use App\Enums\GameStatus;
use App\Exceptions\NotEnoughCardsException;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\User;
use App\Services\GameDealerService;
use Database\Seeders\CardSeeder;

function makeDealtGame(int $playerCount, bool $variant = false): GameSession
{
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'DEAL'.str_pad((string) $playerCount, 2, '0', STR_PAD_LEFT),
        'status' => GameStatus::Countdown,
        'host_user_id' => $host->id,
        'variant' => $variant,
    ]);

    for ($i = 0; $i < $playerCount; $i++) {
        $game->gamePlayers()->create([
            'user_id' => $i === 0 ? $host->id : User::factory()->create()->id,
            'seat_index' => $i,
            'is_ready' => true,
        ]);
    }

    return $game;
}

beforeEach(function () {
    $this->seed(CardSeeder::class);
});

test('every player is dealt 6 piles of 4 cards', function (int $playerCount, bool $variant) {
    $game = makeDealtGame($playerCount, $variant);

    app(GameDealerService::class)->deal($game);

    foreach ($game->gamePlayers()->with('piles.pileCards')->get() as $player) {
        expect($player->piles)->toHaveCount(6);

        foreach ($player->piles as $pile) {
            expect($pile->pileCards)->toHaveCount(4, "seat {$player->seat_index} pile {$pile->pile_index}");
        }
    }

    expect($game->centerPiles()->count())->toBe($game->centerCardCount($playerCount));
})->with([
    [2, false], [3, false], [4, false], [5, false], [6, false],
    [7, false], [8, false], [9, false], [10, false],
    [2, true], [7, true], [8, true], [10, true],
]);

test('dealing fails loudly rather than shorting the last seats when sets are missing', function () {
    // A card table seeded before the 16-set expansion holds 47 sets — three short
    // of the 50 an 8-player game needs. The shortfall used to drain silently into
    // the last seat's piles instead of failing.
    Card::where('clothing_type', '>=', 47)->delete();

    $game = makeDealtGame(8);

    expect(fn () => app(GameDealerService::class)->deal($game))
        ->toThrow(NotEnoughCardsException::class);

    expect($game->piles()->count())->toBe(0)
        ->and($game->fresh()->started_at)->toBeNull();
});

test('an incomplete set is never dealt', function () {
    Card::where('clothing_type', 0)->orderBy('id')->first()->delete();

    $game = makeDealtGame(10);

    app(GameDealerService::class)->deal($game);

    $dealt = $game->piles()->with('pileCards.card')->get()
        ->flatMap(fn ($pile) => $pile->pileCards->pluck('card.clothing_type'));

    expect($dealt)->toHaveCount(248)
        ->and($dealt->contains(ClothingType::Socks))->toBeFalse();
});

test('no card is dealt twice in a full table', function () {
    $game = makeDealtGame(10);

    app(GameDealerService::class)->deal($game);

    $cardIds = $game->piles()->with('pileCards')->get()
        ->flatMap(fn ($pile) => $pile->pileCards->pluck('card_id'));

    expect($cardIds)->toHaveCount(248)
        ->and($cardIds->unique())->toHaveCount(248);
});
