<?php

namespace App\Services;

/**
 * One swap a bot has decided on: its card out of one of its piles, for the
 * top card of a centre pile, at the version of the centre it saw.
 */
final readonly class BotMove
{
    public function __construct(
        public int $pileId,
        public int $myCardId,
        public int $centerPileId,
        public int $centerCardId,
        public int $centerVersion,
    ) {}
}
