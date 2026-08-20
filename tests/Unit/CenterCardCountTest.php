<?php

use App\Models\GameSession;

test('base games with fewer than 9 players get 4 centre cards', function (int $playerCount) {
    $game = new GameSession(['variant' => false]);

    expect($game->centerCardCount($playerCount))->toBe(4);
})->with([2, 3, 4, 5, 6, 7, 8]);

test('base games with 9 or more players get 8 centre cards', function (int $playerCount) {
    $game = new GameSession(['variant' => false]);

    expect($game->centerCardCount($playerCount))->toBe(8);
})->with([9, 10]);

test('variant games always get 8 centre cards regardless of player count', function (int $playerCount) {
    $game = new GameSession(['variant' => true]);

    expect($game->centerCardCount($playerCount))->toBe(8);
})->with([2, 5, 7, 10]);
