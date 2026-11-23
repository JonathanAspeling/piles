<?php

use App\Enums\GameStatus;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('a visitor can play as a guest with just a name', function () {
    $response = $this->post(route('guest.store'), ['name' => 'Speedy']);

    $guest = User::sole();

    expect($guest->name)->toBe('Speedy')
        ->and($guest->email)->toBeNull()
        ->and($guest->password)->toBeNull();

    $this->assertAuthenticatedAs($guest);
    $response->assertRedirect(route('lobby.index', absolute: false));
    $response->assertCookie(Auth::guard('web')->getRecallerName());
});

test('a guest needs a name', function () {
    $this->post(route('guest.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->assertGuest();
});

test('a signed-out visitor following a game link lands back on it after picking a name', function () {
    $game = GameSession::create([
        'code' => 'GUEST1',
        'status' => GameStatus::Lobby,
        'host_user_id' => User::factory()->create()->id,
        'variant' => false,
    ]);

    $this->get(route('games.show', $game))->assertRedirect(route('home'));

    $this->post(route('guest.store'), ['name' => 'Speedy'])
        ->assertRedirect(route('games.show', $game));
});

test('signed-in players cannot start a guest session', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('guest.store'), ['name' => 'Speedy'])
        ->assertRedirect();

    expect(User::count())->toBe(1);
});
