<?php

use App\Enums\CardColor;
use App\Enums\ClothingType;
use App\Enums\GameStatus;
use App\Events\CenterPileSwapped;
use App\Events\GameEnded;
use App\Events\GameResumed;
use App\Events\PlayerPileCompleted;
use App\Jobs\BotMoveJob;
use App\Models\Card;
use App\Models\GamePlayer;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\User;
use App\Services\BotBrain;
use App\Services\CardSwapService;
use App\Services\GameDealerService;
use App\Services\PilesClaimService;
use Database\Seeders\CardSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Random\Engine\Mt19937;
use Random\Randomizer;

beforeEach(function () {
    $this->seed(CardSeeder::class);
    Event::fake([CenterPileSwapped::class, PlayerPileCompleted::class, GameEnded::class, GameResumed::class]);
    Queue::fake();
});

/** One card of the given type, in the given colour. */
function card(ClothingType $type, CardColor $color = CardColor::Red): Card
{
    return Card::where('clothing_type', $type)->where('color', $color)->firstOrFail();
}

/**
 * A playing game with one bot, its piles and the centre laid out by hand.
 *
 * @param  list<list<Card>>  $piles
 * @param  list<Card>  $centre
 * @return array{GameSession, GamePlayer}
 */
function botTable(array $piles, array $centre): array
{
    $host = User::factory()->create();
    $game = GameSession::create(['code' => 'BOTRIG', 'status' => GameStatus::Playing, 'host_user_id' => $host->id, 'variant' => false, 'started_at' => now()]);
    $game->gamePlayers()->create(['user_id' => $host->id, 'seat_index' => 0, 'is_ready' => true]);
    $bot = $game->gamePlayers()->create(['user_id' => User::factory()->bot()->create()->id, 'seat_index' => 1, 'is_ready' => true]);

    foreach ($piles as $index => $cards) {
        $pile = Pile::create(['game_session_id' => $game->id, 'game_player_id' => $bot->id, 'pile_index' => $index, 'is_completed' => false, 'version' => 0]);
        foreach ($cards as $position => $card) {
            $pile->pileCards()->create(['card_id' => $card->id, 'position' => $position]);
        }
    }

    foreach ($centre as $index => $card) {
        Pile::create(['game_session_id' => $game->id, 'game_player_id' => null, 'pile_index' => $index, 'is_completed' => false, 'version' => 3])
            ->pileCards()->create(['card_id' => $card->id, 'position' => 0]);
    }

    return [$game, $bot];
}

function brain(float $attention = 1.0, float $churn = 0.0, int $seed = 1): BotBrain
{
    return new BotBrain(new Randomizer(new Mt19937($seed)), $attention, $churn);
}

test('a bot swaps its odd card for the centre card its pile is collecting', function () {
    $jeans = card(ClothingType::Jeans);
    [$game, $bot] = botTable(
        piles: [[card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Socks, CardColor::Green), $jeans]],
        centre: [card(ClothingType::Dress), card(ClothingType::Socks, CardColor::Yellow)],
    );

    $move = brain()->nextMove($game, $bot);

    $socksPile = $game->centerPiles()->where('pile_index', 1)->first();
    expect($move->myCardId)->toBe($jeans->id)
        ->and($move->centerPileId)->toBe($socksPile->id)
        ->and($move->centerCardId)->toBe(card(ClothingType::Socks, CardColor::Yellow)->id)
        ->and($move->centerVersion)->toBe(3);
});

test('it works on the pile closest to done first', function () {
    [$game, $bot] = botTable(
        piles: [
            [card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Jeans), card(ClothingType::Dress)],
            [card(ClothingType::Blouse), card(ClothingType::Blouse, CardColor::Blue), card(ClothingType::Blouse, CardColor::Green), card(ClothingType::Shorts)],
        ],
        centre: [card(ClothingType::Socks, CardColor::Green), card(ClothingType::Blouse, CardColor::Yellow)],
    );

    $move = brain()->nextMove($game, $bot);

    expect($move->centerCardId)->toBe(card(ClothingType::Blouse, CardColor::Yellow)->id)
        ->and($move->myCardId)->toBe(card(ClothingType::Shorts)->id);
});

test('two piles never chase the same item', function () {
    // Both piles hold two socks. Only one can collect them; the other must not
    // take the socks in the centre for itself.
    [$game, $bot] = botTable(
        piles: [
            [card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Jeans), card(ClothingType::Dress)],
            [card(ClothingType::Socks, CardColor::Green), card(ClothingType::Shorts), card(ClothingType::Shorts, CardColor::Blue), card(ClothingType::Blouse)],
        ],
        centre: [card(ClothingType::Socks, CardColor::Yellow)],
    );

    $move = brain()->nextMove($game, $bot);

    expect($move->pileId)->toBe($bot->piles()->where('pile_index', 0)->value('id'))
        ->and($move->myCardId)->toBeIn([card(ClothingType::Jeans)->id, card(ClothingType::Dress)->id]);
});

test('with nothing useful in the centre it waits, or trades a spare card to keep things moving', function () {
    $piles = [[card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Socks, CardColor::Green), card(ClothingType::Jeans)]];
    [$game, $bot] = botTable($piles, centre: [card(ClothingType::Dress)]);

    expect(brain(churn: 0.0)->nextMove($game, $bot))->toBeNull();

    $move = brain(churn: 1.0)->nextMove($game, $bot);
    expect($move->myCardId)->toBe(card(ClothingType::Jeans)->id)
        ->and($move->centerCardId)->toBe(card(ClothingType::Dress)->id);
});

test('a trade to keep things moving never takes back the card it just gave away', function () {
    $dress = card(ClothingType::Dress);
    [$game, $bot] = botTable(
        piles: [[card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Socks, CardColor::Green), card(ClothingType::Jeans)]],
        centre: [$dress],
    );

    expect(brain(churn: 1.0)->nextMove($game, $bot, lastGivenCardId: $dress->id))->toBeNull();
});

test('an inattentive bot can miss a useful card', function () {
    [$game, $bot] = botTable(
        piles: [[card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Socks, CardColor::Green), card(ClothingType::Jeans)]],
        centre: [card(ClothingType::Socks, CardColor::Yellow)],
    );

    expect(brain(attention: 0.0)->nextMove($game, $bot))->toBeNull();
});

test('a beat makes the move, completes the pile and queues the next beat', function () {
    [$game, $bot] = botTable(
        piles: [[card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Socks, CardColor::Green), card(ClothingType::Jeans)]],
        centre: [card(ClothingType::Socks, CardColor::Yellow)],
    );

    (new BotMoveJob($bot->id))->handle(brain(), app(CardSwapService::class), app(PilesClaimService::class));

    expect($bot->piles()->first()->is_completed)->toBeTrue();
    Queue::assertPushed(BotMoveJob::class, fn (BotMoveJob $job) => $job->gamePlayerId === $bot->id
        && $job->lastGivenCardId === card(ClothingType::Jeans)->id
        && $job->movesMade === 1);
});

test('a bot whose centre card was just taken looks again next beat', function () {
    [$game, $bot] = botTable(
        piles: [[card(ClothingType::Socks), card(ClothingType::Socks, CardColor::Blue), card(ClothingType::Socks, CardColor::Green), card(ClothingType::Jeans)]],
        centre: [card(ClothingType::Socks, CardColor::Yellow)],
    );
    $brain = Mockery::mock(BotBrain::class);
    $brain->shouldReceive('nextMove')->andReturnUsing(function () use ($game, $bot) {
        $move = brain()->nextMove($game, $bot);
        // Someone else swaps at that centre pile first.
        $game->centerPiles()->first()->increment('version');

        return $move;
    });

    (new BotMoveJob($bot->id))->handle($brain, app(CardSwapService::class), app(PilesClaimService::class));

    expect($bot->piles()->first()->is_completed)->toBeFalse();
    Queue::assertPushed(BotMoveJob::class, fn (BotMoveJob $job) => $job->lastGivenCardId === null);
});

test('a bot calls PILES! once all six piles are done, and its loop ends with the win', function () {
    $types = [ClothingType::Socks, ClothingType::Jeans, ClothingType::Dress, ClothingType::TrenchCoat, ClothingType::PufferJacket, ClothingType::Blouse];
    [$game, $bot] = botTable(
        piles: array_map(fn (ClothingType $type) => array_map(fn (CardColor $color) => card($type, $color), CardColor::cases()), $types),
        centre: [card(ClothingType::Shorts)],
    );
    $bot->piles()->update(['is_completed' => true]);

    (new BotMoveJob($bot->id))->handle(brain(), app(CardSwapService::class), app(PilesClaimService::class));

    $game->refresh();
    expect($game->status)->toBe(GameStatus::Ended)
        ->and($game->winner_user_id)->toBe($bot->user_id)
        ->and($game->ended_at)->not->toBeNull();
    Queue::assertNotPushed(BotMoveJob::class);
});

test('a bot stops once its game is over', function () {
    [$game, $bot] = botTable(piles: [[card(ClothingType::Socks)]], centre: [card(ClothingType::Jeans)]);
    $game->update(['status' => GameStatus::Ended]);

    (new BotMoveJob($bot->id))->handle(brain(), app(CardSwapService::class), app(PilesClaimService::class));

    Queue::assertNotPushed(BotMoveJob::class);
});

test('a table of bots plays a real deal through to a winner', function (int $players, bool $variant, bool $similarItems, int $seed) {
    // Only bots at the table: a person who never moved would hold their cards
    // forever, and some sets could never be finished.
    $host = User::factory()->create();
    $game = GameSession::create([
        'code' => 'BOTSIM',
        'status' => GameStatus::Countdown,
        'host_user_id' => $host->id,
        'variant' => $variant,
        'similar_items' => $similarItems,
    ]);
    $bots = collect(range(0, $players - 1))->map(fn (int $seat) => $game->gamePlayers()->create([
        'user_id' => User::factory()->bot()->create()->id,
        'seat_index' => $seat,
        'is_ready' => true,
    ]));
    app(GameDealerService::class)->deal($game);
    $game->update(['status' => GameStatus::Playing]);

    // The bots take turns until one of them wins. Each turn is what
    // BotMoveJob does, without queueing the next beat.
    $brain = brain(attention: BotBrain::ATTENTION, churn: BotBrain::CHURN, seed: $seed);
    $swaps = app(CardSwapService::class);
    $claims = app(PilesClaimService::class);
    $lastGiven = [];
    $turns = 0;
    $maxTurns = 400 * $players;
    while ($turns < $maxTurns && $game->fresh()->status === GameStatus::Playing) {
        $bot = $bots[$turns % $bots->count()];
        $turns++;

        if ($bot->completedPilesCount() === 6) {
            $claims->claim($game->fresh(), $bot);

            continue;
        }

        $move = $brain->nextMove($game, $bot, $lastGiven[$bot->id] ?? null);
        if ($move) {
            $swaps->move($game, $bot, $move->centerPileId, $move->centerCardId, $move->myCardId, $move->pileId, $move->centerVersion);
            $lastGiven[$bot->id] = $move->myCardId;
        }
    }

    if (getenv('BOT_SIM_REPORT')) {
        fwrite(STDOUT, sprintf("SIM players=%d variant=%d similar=%d seed=%d turns=%d per_bot=%d status=%s done=%s\n",
            $players, $variant, $similarItems, $seed, $turns, intdiv($turns, $players), $game->fresh()->status->value,
            $bots->map(fn ($bot) => $bot->completedPilesCount())->implode(',')));
    }

    $game->refresh();
    expect($game->status)->toBe(GameStatus::Ended)
        ->and($bots->pluck('user_id'))->toContain($game->winner_user_id);
})->with('bot tables');

dataset('bot tables', function () {
    $seeds = getenv('BOT_SIM_SEEDS') ? range(1, (int) getenv('BOT_SIM_SEEDS')) : [7];

    foreach ([2, 5, 10] as $players) {
        foreach ([false, true] as $variant) {
            foreach ([false, true] as $similarItems) {
                foreach ($seeds as $seed) {
                    $name = sprintf('%d players%s%s, seed %d', $players, $variant ? ', variant' : '', $similarItems ? ', similar items' : '', $seed);
                    yield $name => [$players, $variant, $similarItems, $seed];
                }
            }
        }
    }
});
