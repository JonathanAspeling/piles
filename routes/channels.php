<?php

use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('game.{gameSessionId}', function (User $user, int $gameSessionId) {
    $player = GamePlayer::where('game_session_id', $gameSessionId)
        ->where('user_id', $user->id)
        ->first();

    if (! $player) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

Broadcast::channel('game.{gameSessionId}.player.{gamePlayerId}', function (User $user, int $gameSessionId, int $gamePlayerId) {
    return GamePlayer::where('id', $gamePlayerId)
        ->where('game_session_id', $gameSessionId)
        ->where('user_id', $user->id)
        ->exists();
});
