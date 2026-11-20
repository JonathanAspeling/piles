<?php

/**
 * Re-deals a running e2e game so the walkthrough can reach the endgame in a
 * couple of moves instead of hundreds.
 *
 * Only the order of cards already on the table changes — every card stays in
 * the game and every pile keeps its size — so the deck is still valid and the
 * winning claim passes the real verifier.
 *
 * After the re-deal:
 *  - the host has piles 1–5 complete, and pile 6 holds three of one item plus
 *    one stray; the fourth of that item is on top of Center 1;
 *  - the guest's pile 1 holds three of another item plus a stray, with the
 *    fourth on top of Center 2.
 *
 * Piped into `php` inside the app container with GAME_CODE set; prints JSON.
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

$game = GameSession::where('code', getenv('GAME_CODE'))->firstOrFail();

$host = $game->gamePlayers()->where('user_id', $game->host_user_id)->firstOrFail();
$guest = $game->gamePlayers()->where('user_id', '!=', $game->host_user_id)->firstOrFail();

$hostPiles = $host->piles()->get()->values();
$guestPiles = $guest->piles()->get()->values();
$centerPiles = $game->centerPiles()->get()->values();

/** @var Collection<int, Pile> $allPiles */
$allPiles = $hostPiles->concat($guestPiles)->concat($centerPiles);

$pileCards = PileCard::whereIn('pile_id', $allPiles->pluck('id'))->with('card')->orderBy('position')->get();

/** @var array<int, list<int>> $slots pile id => positions, in order */
$slots = $pileCards->groupBy('pile_id')->map(fn (Collection $rows) => $rows->pluck('position')->all())->all();

/** @var Collection<int, list<int>> $cardsByType clothing type => card ids */
$cardsByType = $pileCards->groupBy(fn (PileCard $row) => $row->card->clothing_type->value)
    ->map(fn (Collection $rows) => $rows->pluck('card_id')->all())
    ->shuffle();

$types = $cardsByType->keys();
$hostSetTypes = $types->slice(0, 5)->values();
$hostNearlyType = $types->get(5);
$guestNearlyType = $types->get(6);

$leftover = $types->slice(7)->flatMap(fn (int $type) => $cardsByType[$type])->shuffle()->values()->all();
$hostStray = array_shift($leftover);
$guestStray = array_shift($leftover);

/** @var array<int, list<int>> $assignment pile id => card ids, top first */
$assignment = [];

foreach ($hostSetTypes as $index => $type) {
    $assignment[$hostPiles[$index]->id] = $cardsByType[$type];
}

[$hostMatch, $hostThree] = [$cardsByType[$hostNearlyType][3], array_slice($cardsByType[$hostNearlyType], 0, 3)];
[$guestMatch, $guestThree] = [$cardsByType[$guestNearlyType][3], array_slice($cardsByType[$guestNearlyType], 0, 3)];

$assignment[$hostPiles[5]->id] = [...$hostThree, $hostStray];
$assignment[$guestPiles[0]->id] = [...$guestThree, $guestStray];
$assignment[$centerPiles[0]->id] = [$hostMatch];
$assignment[$centerPiles[1]->id] = [$guestMatch];

foreach ($allPiles as $pile) {
    $assignment[$pile->id] ??= [];

    while (count($assignment[$pile->id]) < count($slots[$pile->id] ?? [])) {
        $assignment[$pile->id][] = array_shift($leftover);
    }
}

DB::transaction(function () use ($allPiles, $assignment, $slots, $hostPiles, $game, $host, $guest) {
    PileCard::whereIn('pile_id', $allPiles->pluck('id'))->delete();

    foreach ($assignment as $pileId => $cardIds) {
        foreach ($cardIds as $offset => $cardId) {
            PileCard::create(['pile_id' => $pileId, 'card_id' => $cardId, 'position' => $slots[$pileId][$offset]]);
        }
    }

    Pile::whereIn('id', $allPiles->pluck('id'))->update(['is_completed' => false]);
    Pile::whereIn('id', $hostPiles->take(5)->pluck('id'))->update(['is_completed' => true]);
    Pile::whereIn('id', $game->centerPiles()->pluck('id'))->increment('version');

    foreach ([$host, $guest] as $player) {
        $player->update(['picked_up_card_id' => null, 'picked_up_pile_id' => null]);
    }
});

echo json_encode(['hostMatchCenter' => 1, 'guestMatchCenter' => 2]);
