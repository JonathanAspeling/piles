<?php

namespace App\Services;

use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Support\Collection;
use Random\Randomizer;

/**
 * Decides a bot's next swap by looking at the table the way a player would.
 *
 * Each unfinished pile collects the item it already holds most of, and no two
 * piles chase the same item. When a centre card is something a pile is
 * collecting, the bot swaps that pile's odd card out for it, if it notices:
 * like a person, it misses some. With nothing useful in the centre it
 * sometimes trades a card it doesn't need anyway, which keeps the centre
 * moving so a table of bots can't stall, but it never takes back the card it
 * just gave away.
 *
 * All randomness, including tie-breaks, comes from the injected Randomizer.
 */
class BotBrain
{
    /** How often a useful centre card is noticed. */
    public const ATTENTION = 0.85;

    /** How often, with nothing useful in the centre, the bot trades a spare card anyway. */
    public const CHURN = 0.35;

    /** A pile holding this many of its item or fewer may give up on it during a trade... */
    public const LET_GO_BELOW = 2;

    /** ...this often. */
    public const LET_GO = 0.5;

    public function __construct(
        private readonly Randomizer $random = new Randomizer,
        private readonly float $attention = self::ATTENTION,
        private readonly float $churn = self::CHURN,
    ) {}

    public function nextMove(GameSession $game, GamePlayer $bot, ?int $lastGivenCardId = null): ?BotMove
    {
        /** @var Collection<int, Pile> $piles */
        $piles = $bot->piles()->where('is_completed', false)->with('pileCards.card')->get();

        if ($piles->isEmpty()) {
            return null;
        }

        /** @var Collection<int, Pile> $centre */
        $centre = $game->centerPiles()->with('pileCards.card')->get()
            ->filter(fn (Pile $pile) => $pile->pileCards->isNotEmpty());

        $targets = $this->targets($piles);

        $progress = $this->progressMoves($piles, $centre, $targets);
        if ($progress->isNotEmpty() && $this->chance($this->attention)) {
            $bestScore = $progress->max('score');

            return $this->pick($progress->where('score', $bestScore))['move'];
        }

        if ($this->chance($this->churn)) {
            return $this->churnMove($piles, $centre, $targets, $lastGivenCardId);
        }

        return null;
    }

    /**
     * Which item each unfinished pile is collecting: the one it already holds
     * most of, settled greedily from the strongest claim down so that no two
     * piles chase the same item. A pile left without one collects nothing in
     * particular, so any of its cards may go.
     *
     * @param  Collection<int, Pile>  $piles
     * @return array<int, int|null> clothing type value by pile id
     */
    private function targets(Collection $piles): array
    {
        $claims = $piles->flatMap(fn (Pile $pile) => $pile->pileCards
            ->countBy(fn (PileCard $pileCard) => $pileCard->card->clothing_type->value)
            ->map(fn (int $count, int $type) => [
                'pile' => $pile->id,
                'type' => $type,
                'count' => $count,
                'tie' => $this->random->nextFloat(),
            ])
            ->values())
            ->sortBy([['count', 'desc'], ['tie', 'asc']]);

        $targets = $piles->mapWithKeys(fn (Pile $pile) => [$pile->id => null])->all();
        $takenTypes = [];

        foreach ($claims as $claim) {
            if ($targets[$claim['pile']] === null && ! in_array($claim['type'], $takenTypes, true)) {
                $targets[$claim['pile']] = $claim['type'];
                $takenTypes[] = $claim['type'];
            }
        }

        return $targets;
    }

    /**
     * Every swap that brings a pile one card closer, scored by how close that
     * pile already is.
     *
     * @param  Collection<int, Pile>  $piles
     * @param  Collection<int, Pile>  $centre
     * @param  array<int, int|null>  $targets
     * @return Collection<int, array{move: BotMove, score: int}>
     */
    private function progressMoves(Collection $piles, Collection $centre, array $targets): Collection
    {
        return $centre->flatMap(function (Pile $centrePile) use ($piles, $targets) {
            $topCard = $centrePile->pileCards->first();
            $type = $topCard->card->clothing_type->value;

            return $piles
                ->filter(fn (Pile $pile) => $targets[$pile->id] === $type)
                ->map(function (Pile $pile) use ($centrePile, $topCard, $type, $targets) {
                    $spare = $this->spareCard($pile, $type, $targets);

                    return $spare === null ? null : [
                        'move' => $this->move($pile, $spare, $centrePile, $topCard),
                        'score' => $pile->pileCards->filter(fn (PileCard $pileCard) => $pileCard->card->clothing_type->value === $type)->count(),
                    ];
                })
                ->filter();
        })->values();
    }

    /**
     * Trades a spare card from the pile furthest from done for a centre card,
     * just to keep the centre moving. Never takes back the card it last gave.
     *
     * @param  Collection<int, Pile>  $piles
     * @param  Collection<int, Pile>  $centre
     * @param  array<int, int|null>  $targets
     */
    private function churnMove(Collection $piles, Collection $centre, array $targets, ?int $lastGivenCardId): ?BotMove
    {
        $pilesWithSpares = $piles
            ->map(fn (Pile $pile) => ['pile' => $pile, 'spare' => $this->spareCard($pile, $targets[$pile->id], $targets)])
            ->filter(fn (array $candidate) => $candidate['spare'] !== null);

        if ($pilesWithSpares->isEmpty()) {
            return null;
        }

        $progressOf = fn (array $candidate) => $candidate['pile']->pileCards
            ->filter(fn (PileCard $pileCard) => $pileCard->card->clothing_type->value === $targets[$candidate['pile']->id])
            ->count();
        $fewest = $pilesWithSpares->min($progressOf);
        ['pile' => $pile, 'spare' => $spare] = $this->pick($pilesWithSpares->filter(fn (array $candidate) => $progressOf($candidate) === $fewest));

        // A weak pile sometimes gives up on its item altogether. Two players
        // each holding two of the same item would otherwise wait on each other
        // forever; letting one go frees the item for whoever is further along.
        if ($fewest <= self::LET_GO_BELOW && $this->chance(self::LET_GO)) {
            $spare = $this->pick($pile->pileCards);
        }

        $takeable = $centre->filter(function (Pile $centrePile) use ($spare, $lastGivenCardId) {
            $topCard = $centrePile->pileCards->first();

            return $topCard->card_id !== $lastGivenCardId
                && $topCard->card->clothing_type !== $spare->card->clothing_type;
        });

        if ($takeable->isEmpty()) {
            return null;
        }

        $centrePile = $this->pick($takeable);

        return $this->move($pile, $spare, $centrePile, $centrePile->pileCards->first());
    }

    /**
     * The card a pile can best do without when collecting $type: one no pile
     * is collecting if there is one, otherwise any that isn't $type.
     *
     * @param  array<int, int|null>  $targets
     */
    private function spareCard(Pile $pile, ?int $type, array $targets): ?PileCard
    {
        $spares = $pile->pileCards->filter(fn (PileCard $pileCard) => $pileCard->card->clothing_type->value !== $type);

        if ($spares->isEmpty()) {
            return null;
        }

        $wanted = array_filter($targets, fn (?int $target) => $target !== null);
        $unwanted = $spares->reject(fn (PileCard $pileCard) => in_array($pileCard->card->clothing_type->value, $wanted, true));

        return $this->pick($unwanted->isNotEmpty() ? $unwanted : $spares);
    }

    private function move(Pile $pile, PileCard $spare, Pile $centrePile, PileCard $topCard): BotMove
    {
        return new BotMove(
            pileId: $pile->id,
            myCardId: $spare->card_id,
            centerPileId: $centrePile->id,
            centerCardId: $topCard->card_id,
            centerVersion: $centrePile->version,
        );
    }

    private function chance(float $probability): bool
    {
        return $this->random->nextFloat() < $probability;
    }

    /**
     * @template T
     *
     * @param  Collection<array-key, T>  $items
     * @return T
     */
    private function pick(Collection $items): mixed
    {
        $values = $items->values();

        return $values[$this->random->getInt(0, $values->count() - 1)];
    }
}
