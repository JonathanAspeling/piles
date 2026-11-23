<?php

namespace App\Services;

use App\Enums\ClothingType;
use App\Enums\DeckMode;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Chooses which item sets go into a game's deck (see ClothingSimilarity for
 * which items look alike).
 *
 * - Distinct: picks one item at a time, always one with the fewest look-alikes
 *   already in the deck. So it only adds an item with a look-alike once no
 *   fully distinct item is left.
 * - SimilarItems: picks items at random, with no preference for any group, but
 *   look-alikes always come in at least pairs. When a picked item has no
 *   look-alike in the deck yet, one random look-alike joins it straight away.
 *   Every slot only takes an item that won't be left on its own: an ungrouped
 *   item, one with a look-alike already in the deck, or (with room for two)
 *   one whose look-alike is still available. Only when no such item is left
 *   does an item go in alone.
 *
 * The eligible items are shuffled once up front, so taking the first match in
 * order is a random choice.
 *
 * All randomness, including tie-breaks, comes from the injected Randomizer.
 */
class DeckBuilder
{
    public function __construct(private readonly Randomizer $random = new Randomizer) {}

    /**
     * @param  list<ClothingType>  $eligible  items with a complete set available
     * @return list<ClothingType>
     */
    public function pick(array $eligible, int $count, DeckMode $mode): array
    {
        if ($count < 0 || $count > count($eligible)) {
            throw new InvalidArgumentException("Cannot pick {$count} sets from ".count($eligible).' eligible items.');
        }

        $values = array_map(fn (ClothingType $type) => $type->value, $eligible);

        if (count(array_unique($values)) !== count($eligible)) {
            throw new InvalidArgumentException('Eligible items must not repeat.');
        }

        $shuffled = $this->random->shuffleArray($eligible);

        return $mode === DeckMode::SimilarItems
            ? $this->pickInPairs($shuffled, $count)
            : $this->pickDistinct($shuffled, $count);
    }

    /**
     * @param  list<ClothingType>  $remaining  shuffled
     * @return list<ClothingType>
     */
    private function pickDistinct(array $remaining, int $count): array
    {
        $picked = [];
        $similarPickedCount = [];
        foreach ($remaining as $type) {
            $similarPickedCount[$type->value] = 0;
        }

        while (count($picked) < $count) {
            $bestIndex = null;

            foreach ($remaining as $index => $type) {
                if ($bestIndex === null || $similarPickedCount[$type->value] < $similarPickedCount[$remaining[$bestIndex]->value]) {
                    $bestIndex = $index;
                }
            }

            $chosen = $remaining[$bestIndex];
            unset($remaining[$bestIndex]);
            $picked[] = $chosen;

            foreach (ClothingSimilarity::similarTo($chosen) as $similar) {
                if (isset($similarPickedCount[$similar->value])) {
                    $similarPickedCount[$similar->value]++;
                }
            }
        }

        return $picked;
    }

    /**
     * @param  list<ClothingType>  $remaining  shuffled
     * @return list<ClothingType>
     */
    private function pickInPairs(array $remaining, int $count): array
    {
        $picked = [];

        while (count($picked) < $count) {
            $hasRoomForPair = $count - count($picked) >= 2;

            $chosenIndex = $this->firstIndex(
                $remaining,
                fn (ClothingType $type) => ! $this->wouldBeAlone($type, $picked)
                    || ($hasRoomForPair && $this->hasLookAlikeIn($type, $remaining)),
            ) ?? array_key_first($remaining);

            $chosen = $remaining[$chosenIndex];
            $needsPartner = $this->wouldBeAlone($chosen, $picked);
            unset($remaining[$chosenIndex]);
            $picked[] = $chosen;

            if ($needsPartner && count($picked) < $count) {
                $partnerIndex = $this->firstIndex($remaining, fn (ClothingType $type) => ClothingSimilarity::areSimilar($chosen, $type));

                if ($partnerIndex !== null) {
                    $picked[] = $remaining[$partnerIndex];
                    unset($remaining[$partnerIndex]);
                }
            }
        }

        return $picked;
    }

    /**
     * Whether adding this item would leave a grouped item with no look-alike in the deck.
     *
     * @param  list<ClothingType>  $picked
     */
    private function wouldBeAlone(ClothingType $type, array $picked): bool
    {
        if (ClothingSimilarity::similarTo($type) === []) {
            return false;
        }

        foreach ($picked as $other) {
            if (ClothingSimilarity::areSimilar($type, $other)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any of the given items looks like this one.
     *
     * @param  array<int, ClothingType>  $items
     */
    private function hasLookAlikeIn(ClothingType $type, array $items): bool
    {
        return $this->firstIndex($items, fn (ClothingType $other) => ClothingSimilarity::areSimilar($type, $other)) !== null;
    }

    /**
     * @param  array<int, ClothingType>  $items
     * @param  callable(ClothingType): bool  $matches
     */
    private function firstIndex(array $items, callable $matches): ?int
    {
        foreach ($items as $index => $type) {
            if ($matches($type)) {
                return $index;
            }
        }

        return null;
    }
}
