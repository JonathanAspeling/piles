<?php

use App\Enums\GameStatus;
use App\Events\GameLobbyUpdated;
use App\Events\LobbyUpdated;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Event::fake([GameLobbyUpdated::class, LobbyUpdated::class]);
});

function inviteLobby(int $seats = 1, GameStatus $status = GameStatus::Lobby): GameSession
{
    $host = User::factory()->create();

    $game = GameSession::create([
        'code' => 'INVITE',
        'status' => $status,
        'host_user_id' => $host->id,
        'variant' => false,
    ]);

    for ($seat = 0; $seat < $seats; $seat++) {
        $game->gamePlayers()->create([
            'user_id' => $seat === 0 ? $host->id : User::factory()->create()->id,
            'seat_index' => $seat,
            'is_ready' => false,
        ]);
    }

    return $game;
}

test('a signed-in player following an invite link is seated and taken to the room', function () {
    $game = inviteLobby();
    $friend = User::factory()->create();

    $this->actingAs($friend)
        ->get(route('games.invite', ['code' => 'INVITE']))
        ->assertRedirect(route('games.show', $game));

    expect($game->gamePlayers()->where('user_id', $friend->id)->value('seat_index'))->toBe(1);
    Event::assertDispatched(GameLobbyUpdated::class);
    Event::assertDispatched(LobbyUpdated::class);
});

test('invite codes are not case sensitive', function () {
    $game = inviteLobby();

    $this->actingAs(User::factory()->create())
        ->get('/join/invite')
        ->assertRedirect(route('games.show', $game));

    expect($game->gamePlayers()->count())->toBe(2);
});

test('following your own invite link again just reopens the room', function () {
    $game = inviteLobby();

    $this->actingAs($game->host)
        ->get(route('games.invite', ['code' => 'INVITE']))
        ->assertRedirect(route('games.show', $game));

    expect($game->gamePlayers()->count())->toBe(1);
    Event::assertNotDispatched(GameLobbyUpdated::class);
});

test('a player already in a running game is taken back to it', function () {
    $game = inviteLobby(2, GameStatus::Playing);
    $player = $game->gamePlayers()->where('seat_index', 1)->first()->user;

    $this->actingAs($player)
        ->get(route('games.invite', ['code' => 'INVITE']))
        ->assertRedirect(route('games.show', $game));
});

test('an invite link that cannot be used lands in the lobby with the reason', function (Closure $makeGame, string $reason) {
    $makeGame();

    $this->actingAs(User::factory()->create())
        ->get(route('games.invite', ['code' => 'INVITE']))
        ->assertRedirect(route('lobby.index'))
        ->assertSessionHas('joinError', $reason);
})->with([
    'started' => [fn () => inviteLobby(2, GameStatus::Playing), 'This game has already started.'],
    'full' => [fn () => inviteLobby(10), 'This game is full.'],
    'unknown' => [fn () => null, "That invite link doesn't match a game."],
]);

test('the lobby shows why an invite link could not be used', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['joinError' => 'This game is full.'])
        ->get(route('lobby.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lobby/Index')
            ->where('flash.joinError', 'This game is full.'));
});

test('a signed-out visitor is sent home, told about the invite, and comes back after picking a name', function () {
    $game = inviteLobby();

    $this->get(route('games.invite', ['code' => 'INVITE']))
        ->assertRedirect(route('home'));

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('inviteCode', 'INVITE'));

    $this->post(route('guest.store'), ['name' => 'Cheron'])
        ->assertRedirect(route('games.invite', ['code' => 'INVITE']));

    $this->get(route('games.invite', ['code' => 'INVITE']))
        ->assertRedirect(route('games.show', $game));

    expect($game->gamePlayers()->count())->toBe(2);
});

test('the home page has no invite without an invite link', function () {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('inviteCode', null));
});
