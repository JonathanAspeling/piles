<?php

use App\Enums\ClothingType;
use App\Enums\DeckMode;
use App\Services\ClothingSimilarity;
use App\Services\DeckBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;

function seededDeckBuilder(int $seed): DeckBuilder
{
    return new DeckBuilder(new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<ClothingType>  $deck
 */
function similarPairCount(array $deck): int
{
    $pairs = 0;

    foreach ($deck as $index => $type) {
        foreach (array_slice($deck, $index + 1) as $other) {
            $pairs += ClothingSimilarity::areSimilar($type, $other) ? 1 : 0;
        }
    }

    return $pairs;
}

test('both modes return exactly the requested number of distinct items', function (DeckMode $mode, int $count) {
    $deck = seededDeckBuilder($count)->pick(ClothingType::cases(), $count, $mode);

    $values = array_map(fn (ClothingType $type) => $type->value, $deck);

    expect($deck)->toHaveCount($count)
        ->and(array_unique($values))->toHaveCount($count);
})->with([DeckMode::Distinct, DeckMode::SimilarItems])->with([0, 1, 13, 19, 25, 43, 61, 63]);

test('a normal 2-player deck (13 sets) never has two look-alike items', function (int $seed) {
    $deck = seededDeckBuilder($seed)->pick(ClothingType::cases(), 13, DeckMode::Distinct);

    expect(similarPairCount($deck))->toBe(0);
})->with(range(1, 25));

test('normal mode only adds a look-alike once no distinct item is left', function (int $seed) {
    $deck = seededDeckBuilder($seed)->pick(ClothingType::cases(), 43, DeckMode::Distinct);

    foreach ($deck as $index => $type) {
        $pickedBefore = array_slice($deck, 0, $index);
        $clashes = fn (ClothingType $candidate) => array_filter($pickedBefore, fn (ClothingType $picked) => ClothingSimilarity::areSimilar($candidate, $picked)) !== [];

        if ($clashes($type)) {
            $distinctLeft = array_filter(
                ClothingType::cases(),
                fn (ClothingType $candidate) => ! in_array($candidate, $pickedBefore, true) && ! $clashes($candidate),
            );

            expect($distinctLeft)->toBe([], "{$type->name} was picked while a distinct item was left");
        }
    }
})->with([1, 2, 3, 4, 5]);

test('in similar items mode every grouped item has a look-alike in the deck', function (int $count) {
    foreach (range(1, 60) as $seed) {
        $deck = seededDeckBuilder($seed)->pick(ClothingType::cases(), $count, DeckMode::SimilarItems);

        foreach ($deck as $type) {
            if (ClothingSimilarity::similarTo($type) === []) {
                continue;
            }

            $lookAlikes = array_filter($deck, fn (ClothingType $other) => ClothingSimilarity::areSimilar($type, $other));

            expect($lookAlikes)->not->toBeEmpty("seed {$seed}: {$type->name} has no look-alike in a {$count}-set deck");
        }
    }
})->with([13, 19, 25]);

test('similar items decks stay varied instead of being a few whole groups', function () {
    $groupsTouched = function (array $deck): int {
        $deckValues = array_map(fn (ClothingType $type) => $type->value, $deck);

        return count(array_filter(
            ClothingSimilarity::GROUPS,
            fn (array $group) => array_intersect(array_map(fn (ClothingType $type) => $type->value, $group), $deckValues) !== [],
        ));
    };

    $spans = array_map(
        fn (int $seed) => $groupsTouched(seededDeckBuilder($seed)->pick(ClothingType::cases(), 13, DeckMode::SimilarItems)),
        range(1, 100),
    );

    // Growing whole clusters touched about 4 groups in a 13-set deck; pairs drawn
    // at random spread across about 6.
    expect(array_sum($spans) / count($spans))->toBeGreaterThan(5.5)
        ->and(min($spans))->toBeGreaterThanOrEqual(4);
});

test('similar items mode only leaves an item without a partner when nothing else fits', function () {
    // One slot, and every eligible item is grouped with nothing of its kind in the deck yet.
    $alone = [ClothingType::Sneakers, ClothingType::Tie, ClothingType::Gloves];

    foreach (range(1, 20) as $seed) {
        $deck = seededDeckBuilder($seed)->pick($alone, 1, DeckMode::SimilarItems);

        expect($deck)->toHaveCount(1)
            ->and($deck[0])->toBeIn($alone);
    }

    // With an ungrouped item available, the last slot takes it rather than an unpaired one.
    foreach (range(1, 20) as $seed) {
        expect(seededDeckBuilder($seed)->pick([ClothingType::Sneakers, ClothingType::Tie, ClothingType::Socks], 1, DeckMode::SimilarItems))
            ->toBe([ClothingType::Socks]);
    }
});

test('similar items mode skips an item whose look-alikes are all missing while a pair still fits', function () {
    foreach (range(1, 20) as $seed) {
        $deck = seededDeckBuilder($seed)->pick([ClothingType::Sneakers, ClothingType::JoggingShoes, ClothingType::Tie, ClothingType::Socks], 3, DeckMode::SimilarItems);

        expect($deck)->toEqualCanonicalizing([ClothingType::Sneakers, ClothingType::JoggingShoes, ClothingType::Socks], "seed {$seed}");
    }
});

test('a look-alike joins a picked item straight away', function () {
    foreach (range(1, 20) as $seed) {
        $deck = seededDeckBuilder($seed)->pick([ClothingType::Sneakers, ClothingType::JoggingShoes, ClothingType::Socks, ClothingType::Umbrella], 2, DeckMode::SimilarItems);

        $shoes = array_filter($deck, fn (ClothingType $type) => in_array($type, [ClothingType::Sneakers, ClothingType::JoggingShoes], true));

        expect(count($shoes))->toBeIn([0, 2], "seed {$seed}: ".implode(', ', array_map(fn (ClothingType $type) => $type->name, $deck)));
    }
});

test('the same random source gives the same deck', function (DeckMode $mode) {
    expect(seededDeckBuilder(42)->pick(ClothingType::cases(), 19, $mode))
        ->toBe(seededDeckBuilder(42)->pick(ClothingType::cases(), 19, $mode));
})->with([DeckMode::Distinct, DeckMode::SimilarItems]);

test('asking for more sets than are eligible is rejected', function () {
    seededDeckBuilder(1)->pick([ClothingType::Socks, ClothingType::Jeans], 3, DeckMode::Distinct);
})->throws(InvalidArgumentException::class);
