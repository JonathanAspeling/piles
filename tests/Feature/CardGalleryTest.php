<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('card gallery renders for a signed-in player', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('cards.index'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Cards/Index'));
});

test('card gallery requires sign-in', function () {
    $this->get(route('cards.index'))->assertRedirect(route('home'));
});

test('card gallery is hidden in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs(User::factory()->create())
        ->get(route('cards.index'))
        ->assertNotFound();
});
