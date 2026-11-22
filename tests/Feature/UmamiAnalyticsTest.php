<?php

test('the umami tracker loads when a website id is configured', function () {
    config([
        'services.umami.website_id' => 'test-website-id',
        'services.umami.script_url' => 'https://stats.example.test/script.js',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('<script defer src="https://stats.example.test/script.js" data-website-id="test-website-id"></script>', false);
});

test('the umami tracker is left out without a website id', function () {
    config(['services.umami.website_id' => null]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('data-website-id', false);
});
