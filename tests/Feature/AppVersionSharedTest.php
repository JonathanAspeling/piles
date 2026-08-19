<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the app version is shared with every inertia page', function () {
    config(['app.version' => 'v9.9.9']);

    $this->actingAs(User::factory()->create())
        ->get(route('lobby.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('appVersion', 'v9.9.9'));
});

test('the app version falls back to dev when APP_VERSION is unset', function () {
    expect(config('app.version'))->toBe('dev');
});
