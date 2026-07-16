<?php

use App\Http\Controllers\GameplayController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\LobbyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/lobby', [LobbyController::class, 'index'])->name('lobby.index');

    Route::post('/games', [GameSessionController::class, 'store'])->name('games.store');
    Route::post('/games/join', [GameSessionController::class, 'join'])->name('games.join');
    Route::get('/games/{game}', [GameSessionController::class, 'show'])->name('games.show');
    Route::post('/games/{game}/ready', [GameSessionController::class, 'ready'])->name('games.ready');
    Route::post('/games/{game}/start', [GameSessionController::class, 'start'])->name('games.start');
    Route::post('/games/{game}/client-ready', [GameSessionController::class, 'clientReady'])->name('games.client-ready');
    Route::delete('/games/{game}/leave', [GameSessionController::class, 'leave'])->name('games.leave');

    Route::post('/games/{game}/swap', [GameplayController::class, 'swap'])->name('gameplay.swap');
    Route::post('/games/{game}/claim', [GameplayController::class, 'claimPiles'])->name('gameplay.claim');
    Route::post('/games/{game}/forfeit', [GameplayController::class, 'forfeit'])->name('gameplay.forfeit');
});
