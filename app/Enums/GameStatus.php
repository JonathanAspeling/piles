<?php

namespace App\Enums;

enum GameStatus: string
{
    case Lobby = 'lobby';
    case Countdown = 'countdown';
    case Playing = 'playing';
    case Verifying = 'verifying';
    case Ended = 'ended';
}
