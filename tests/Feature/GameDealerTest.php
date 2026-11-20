<?php

use App\Enums\ClothingType;
use App\Enums\GameStatus;
use App\Exceptions\NotEnoughCardsException;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\User;
use App\Services\ClothingSimilarity;
use App\Services\GameDealerService;
use Database\Seeders\CardSeeder;

function makeDealtGame(int $playerCount, bool $similarItems = false): GameSession
{
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'DEAL'.str_pad((string) $playerCount, 2, '0', STR_PAD_LEFT),
        'status' => GameStatus::Countdown,
        'host_user_id' => $host->id,
        'similar_items' => $similarItems,
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

test('every player is dealt 6 piles of 4 cards', function (int $playerCount, bool $similarItems) {
    $game = makeDealtGame($playerCount, $similarItems);

    app(GameDealerService::class)->deal($game);

    foreach ($game->gamePlayers()->with('piles.pileCards')->get() as $player) {
        expect($player->piles)->toHaveCount(6);

        foreach ($player->piles as $pile) {
            expect($pile->pileCards)->toHaveCount(4, "seat {$player->seat_index} pile {$pile->pile_index}");
        }
    }

    expect($game->centerPiles()->count())->toBe(GameDealerService::CENTER_CARDS);
})->with([
    [2, false], [3, false], [4, false], [5, false], [6, false],
    [7, false], [8, false], [9, false], [10, false],
    [2, true], [5, true], [8, true], [9, true], [10, true],
]);

test('the deck is 6 sets per player plus 1, with 4 centre cards, in both deck modes', function (int $playerCount, bool $similarItems) {
    $game = makeDealtGame($playerCount, $similarItems);

    app(GameDealerService::class)->deal($game);

    $expectedSets = 6 * $playerCount + 1;

    $dealtTypes = $game->piles()->with('pileCards.card')->get()
        ->flatMap(fn ($pile) => $pile->pileCards->pluck('card.clothing_type'));

    expect($game->fresh()->sets_count)->toBe($expectedSets)
        ->and($game->centerPiles()->count())->toBe(4)
        ->and($dealtTypes)->toHaveCount($expectedSets * 4)
        ->and($dealtTypes->unique())->toHaveCount($expectedSets);
})->with(function () {
    foreach (range(2, 10) as $playerCount) {
        yield "{$playerCount} players" => [$playerCount, false];
        yield "{$playerCount} players, similar items" => [$playerCount, true];
    }
});

test('a 2-player deck has no look-alike items unless similar items is on', function () {
    $game = makeDealtGame(2);

    app(GameDealerService::class)->deal($game);

    $types = $game->piles()->with('pileCards.card')->get()
        ->flatMap(fn ($pile) => $pile->pileCards->pluck('card.clothing_type'))
        ->unique()
        ->values();

    foreach ($types as $type) {
        foreach ($types as $other) {
            expect(ClothingSimilarity::areSimilar($type, $other))->toBeFalse("{$type->name} and {$other->name}");
        }
    }
});

test('dealing fails loudly rather than shorting the last seats when sets are missing', function () {
    // A card table seeded before the 16-set expansion holds 47 sets — two short
    // of the 49 an 8-player game needs. The shortfall used to drain silently into
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

    // Two sets broken leaves 61 complete: exactly what 10 players need.
    Card::where('clothing_type', 1)->orderBy('id')->first()->delete();

    $game = makeDealtGame(10);

    app(GameDealerService::class)->deal($game);

    $dealt = $game->piles()->with('pileCards.card')->get()
        ->flatMap(fn ($pile) => $pile->pileCards->pluck('card.clothing_type'));

    expect($dealt)->toHaveCount(244)
        ->and($dealt->contains(ClothingType::Socks))->toBeFalse()
        ->and($dealt->contains(ClothingType::Jeans))->toBeFalse();
});

test('no card is dealt twice in a full table', function () {
    $game = makeDealtGame(10);

    app(GameDealerService::class)->deal($game);

    $cardIds = $game->piles()->with('pileCards')->get()
        ->flatMap(fn ($pile) => $pile->pileCards->pluck('card_id'));

    expect($cardIds)->toHaveCount(244)
        ->and($cardIds->unique())->toHaveCount(244);
});
