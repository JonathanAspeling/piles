<?php

use App\Enums\ClothingType;
use App\Services\ClothingSimilarity;

test('every similarity group only lists real clothing types, each once', function () {
    foreach (ClothingSimilarity::GROUPS as $name => $group) {
        expect($group)->not->toBeEmpty("{$name} is empty")
            ->each->toBeInstanceOf(ClothingType::class);

        $values = array_map(fn (ClothingType $type) => $type->value, $group);

        expect($values)->toHaveCount(count(array_unique($values)), "{$name} repeats an item");
    }
});

test('similarity is symmetric and never relates an item to itself', function () {
    foreach (ClothingType::cases() as $type) {
        expect(ClothingSimilarity::areSimilar($type, $type))->toBeFalse();

        foreach (ClothingSimilarity::similarTo($type) as $similar) {
            expect(ClothingSimilarity::areSimilar($similar, $type))->toBeTrue("{$similar->name} -> {$type->name}");
        }
    }
});

test('items are similar exactly when they share a group', function () {
    expect(ClothingSimilarity::areSimilar(ClothingType::Sneakers, ClothingType::FormalShoes))->toBeTrue()
        ->and(ClothingSimilarity::areSimilar(ClothingType::ButtonDownShirt, ClothingType::Blazer))->toBeTrue()
        ->and(ClothingSimilarity::areSimilar(ClothingType::ButtonDownShirt, ClothingType::TShirt))->toBeTrue()
        ->and(ClothingSimilarity::areSimilar(ClothingType::Blazer, ClothingType::TShirt))->toBeFalse()
        ->and(ClothingSimilarity::similarTo(ClothingType::Socks))->toBe([])
        ->and(ClothingSimilarity::similarTo(ClothingType::Sunglasses))->toBe([])
        ->and(ClothingSimilarity::similarTo(ClothingType::Umbrella))->toBe([]);
});

test('every clothing type is in a group except the deliberately unique ones', function () {
    $grouped = array_unique(array_map(
        fn (ClothingType $type) => $type->value,
        array_merge(...array_values(ClothingSimilarity::GROUPS)),
    ));

    $ungrouped = array_values(array_filter(
        ClothingType::cases(),
        fn (ClothingType $type) => ! in_array($type->value, $grouped, true),
    ));

    expect($ungrouped)->toBe([ClothingType::Socks, ClothingType::Sunglasses, ClothingType::Umbrella]);
});
