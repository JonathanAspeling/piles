<?php

use App\Models\User;

test('the welcome page renders for guests', function () {
    $this->get('/')->assertOk();
});

test('a new name creates a player and starts their session', function () {
    $response = $this->post(route('play'), ['name' => 'Sam']);

    $response->assertRedirect(route('lobby.index', absolute: false));
    $this->assertAuthenticatedAs(User::whereName('Sam')->sole());
});

test('a known name reuses the existing player', function () {
    $sam = User::factory()->create(['name' => 'Sam']);

    $this->post(route('play'), ['name' => 'Sam']);

    $this->assertAuthenticatedAs($sam);
    expect(User::count())->toBe(1);
});

test('a known name is matched regardless of casing', function () {
    $sam = User::factory()->create(['name' => 'Sam']);

    $this->post(route('play'), ['name' => 'SAM']);

    $this->assertAuthenticatedAs($sam);
    expect(User::count())->toBe(1);
});

test('surrounding whitespace is trimmed off the name', function () {
    $this->post(route('play'), ['name' => '  Sam  ']);

    expect(User::sole()->name)->toBe('Sam');
});

test('a blank name is rejected', function () {
    $this->post(route('play'), ['name' => '   '])->assertSessionHasErrors('name');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a name longer than thirty characters is rejected', function () {
    $this->post(route('play'), ['name' => str_repeat('a', 31)])->assertSessionHasErrors('name');

    $this->assertGuest();
});

test('players can sign out', function () {
    $response = $this->actingAs(User::factory()->create())->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('guests are sent to the welcome page instead of a login screen', function () {
    $this->get(route('lobby.index'))->assertRedirect(route('home'));
});

test('the email and password auth routes no longer exist', function () {
    $this->get('/login')->assertNotFound();
    $this->get('/register')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
});
