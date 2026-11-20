<?php

namespace App\Enums;

/**
 * The card art a game is drawn with. Picked by the host in the lobby and
 * shared by every player in the game.
 */
enum CardTheme: string
{
    /** White silhouettes on coloured card backgrounds. */
    case Classic = 'classic';

    /** Pre-coloured illustrations, each item in its own four colourways. */
    case Standard = 'standard';
}
