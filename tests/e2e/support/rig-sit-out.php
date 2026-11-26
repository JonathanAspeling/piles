<?php

/**
 * Re-deals a freshly dealt game so the host can sit out while the bots play
 * it to a finish.
 *
 * A player who never moves keeps their 24 cards, and the sets those cards
 * belong to could then never be finished. So the host is given six whole
 * sets instead, one per pile, but left unmarked: their board still reads 0/6
 * and they never call PILES!, yet none of their cards are needed elsewhere.
 * Every other card is shuffled through the bots' piles and the centre, each
 * pile keeping its size. The deck is unchanged, so every claim still goes
 * through the real verifier.
 *
 * Run during the countdown, before any bot moves. Piped into `php` inside
 * the app container with GAME_CODE set; prints JSON.
 */

use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, "rig-sit-out failed: {$exception}\n");
    exit(1);
});

$game = GameSession::where('code', getenv('GAME_CODE'))->firstOrFail();
$host = $game->gamePlayers()->where('user_id', $game->host_user_id)->firstOrFail();

/** @var Collection<int, Pile> $allPiles */
$allPiles = Pile::where('game_session_id', $game->id)->get();
$hostPiles = $allPiles->where('game_player_id', $host->id)->sortBy('pile_index')->values();

if ($hostPiles->count() !== 6) {
    throw new RuntimeException("The host has {$hostPiles->count()} piles; deal the game first.");
}

$pileCards = PileCard::whereIn('pile_id', $allPiles->pluck('id'))->with('card')->orderBy('position')->get();

/** @var array<int, list<int>> $slots pile id => positions, in order */
$slots = $pileCards->groupBy('pile_id')->map(fn (Collection $rows) => $rows->pluck('position')->all())->all();

$cardsByType = $pileCards->groupBy(fn (PileCard $row) => $row->card->clothing_type->value)
    ->map(fn (Collection $rows) => $rows->pluck('card_id')->all())
    ->filter(fn (array $cardIds) => count($cardIds) === 4)
    ->shuffle();

$hostTypes = $cardsByType->keys()->take(6);
$hostCardIds = $hostTypes->flatMap(fn (int $type) => $cardsByType[$type])->all();
$rest = $pileCards->pluck('card_id')->diff($hostCardIds)->shuffle()->values()->all();

/** @var array<int, list<int>> $assignment pile id => card ids, top first */
$assignment = [];
foreach ($hostTypes->values() as $index => $type) {
    $assignment[$hostPiles[$index]->id] = $cardsByType[$type];
}
foreach ($allPiles as $pile) {
    $assignment[$pile->id] ??= [];
    while (count($assignment[$pile->id]) < count($slots[$pile->id] ?? [])) {
        $assignment[$pile->id][] = array_shift($rest);
    }
}

DB::transaction(function () use ($allPiles, $assignment, $slots, $game) {
    PileCard::whereIn('pile_id', $allPiles->pluck('id'))->delete();

    foreach ($assignment as $pileId => $cardIds) {
        foreach ($cardIds as $offset => $cardId) {
            PileCard::create(['pile_id' => $pileId, 'card_id' => $cardId, 'position' => $slots[$pileId][$offset]]);
        }
    }

    Pile::whereIn('id', $allPiles->pluck('id'))->update(['is_completed' => false]);
    Pile::whereIn('id', $game->centerPiles()->pluck('id'))->increment('version');
}, 5);

echo json_encode(['hostSets' => $hostTypes->count(), 'cardsReshuffled' => count($pileCards) - 24]);
