<?php

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Events\GameResumed;
use App\Models\Card;
use App\Models\GameSession;
use App\Models\Pile;
use App\Models\PileCard;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function makeGame(User $host, User $joiner): GameSession
{
    $game = GameSession::create([
        'code' => 'CLAIM1',
        'status' => GameStatus::Playing,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $host->id,
        'seat_index' => 0,
        'is_ready' => true,
    ]);

    $game->gamePlayers()->create([
        'user_id' => $joiner->id,
        'seat_index' => 1,
        'is_ready' => true,
    ]);

    return $game;
}

function seedPiles(GameSession $game, User $claimant, bool $valid): void
{
    $player = $game->gamePlayers()->where('user_id', $claimant->id)->firstOrFail();

    for ($pileIndex = 0; $pileIndex < 6; $pileIndex++) {
        $pile = Pile::create([
            'game_session_id' => $game->id,
            'game_player_id' => $player->id,
            'pile_index' => $pileIndex,
            'is_completed' => true,
            'version' => 0,
        ]);

        for ($color = 0; $color < 4; $color++) {
            // Invalid case: last pile mixes clothing_types so the verifier rejects it,
            // while remaining piles are still valid so the client-side "6 completed"
            // check (which lets the PILES button appear) is satisfied.
            $clothingType = ($valid || $pileIndex < 5 || $color < 3) ? $pileIndex : 20;

            $card = Card::firstOrCreate(
                ['clothing_type' => $clothingType, 'color' => $color],
            );

            PileCard::create([
                'pile_id' => $pile->id,
                'card_id' => $card->id,
                'position' => $color,
            ]);
        }
    }
}

test('a valid PILES claim ends the game and broadcasts a populated winner', function () {
    Event::fake([GameEnded::class, GameResumed::class]);

    $host = User::factory()->create(['name' => 'Winston']);
    $joiner = User::factory()->create();
    $game = makeGame($host, $joiner);
    seedPiles($game, $host, valid: true);

    $this->actingAs($host)
        ->post(route('gameplay.claim', $game))
        ->assertOk();

    $game->refresh();
    expect($game->status)->toBe(GameStatus::Ended);
    expect($game->winner_user_id)->toBe($host->id);

    Event::assertDispatched(GameEnded::class, function (GameEnded $event) use ($host) {
        return $event->winner !== null
            && $event->winner->user_id === $host->id
            && $event->winner->user->name === 'Winston'
            && $event->forfeitedBy === null;
    });
    Event::assertNotDispatched(GameResumed::class);
});

test('an invalid PILES claim broadcasts GameResumed and returns the game to Playing', function () {
    Event::fake([GameEnded::class, GameResumed::class]);

    $host = User::factory()->create(['name' => 'Willow']);
    $joiner = User::factory()->create();
    $game = makeGame($host, $joiner);
    seedPiles($game, $host, valid: false);

    $this->actingAs($host)
        ->post(route('gameplay.claim', $game))
        ->assertOk();

    $game->refresh();
    expect($game->status)->toBe(GameStatus::Playing);
    expect($game->winner_user_id)->toBeNull();

    Event::assertDispatched(GameResumed::class, function (GameResumed $event) {
        return $event->claimantName === 'Willow';
    });
    Event::assertNotDispatched(GameEnded::class);
});

test('a claim in a non-Playing game is rejected', function () {
    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $game = makeGame($host, $joiner);
    $game->update(['status' => GameStatus::Verifying]);

    $this->actingAs($host)
        ->post(route('gameplay.claim', $game))
        ->assertStatus(422);
});
