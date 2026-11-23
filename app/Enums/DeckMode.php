<?php

namespace App\Enums;

/**
 * How the item sets for a game's deck are chosen.
 */
enum DeckMode: string
{
    /** Avoid look-alike items: spread the deck across as many groups as possible. */
    case Distinct = 'distinct';

    /** Hard mode: random items, but look-alikes always come in at least pairs. */
    case SimilarItems = 'similar_items';
}
