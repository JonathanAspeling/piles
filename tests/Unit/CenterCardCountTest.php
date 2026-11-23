<?php

use App\Models\GameSession;

test('base games get 4 centre cards at every player count', function (int $playerCount) {
    $game = new GameSession(['variant' => false]);

    expect($game->centerCardCount($playerCount))->toBe(4);
})->with([2, 3, 4, 5, 6, 7, 8, 9, 10]);

test('9 and 10 player games are not forced into the variant', function (int $playerCount) {
    $game = new GameSession(['variant' => false]);

    expect($game->centerCardCount($playerCount))->toBe(4);
})->with([9, 10]);

test('variant games always get 8 centre cards regardless of player count', function (int $playerCount) {
    $game = new GameSession(['variant' => true]);

    expect($game->centerCardCount($playerCount))->toBe(8);
})->with([2, 3, 4, 5, 6, 7, 8, 9, 10]);
