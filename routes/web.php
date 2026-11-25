<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request) {
    // Arriving from an invite link while signed out: the link waits as the
    // intended URL, so the page can say which game picking a name joins.
    $intendedPath = parse_url((string) $request->session()->get('url.intended'), PHP_URL_PATH);
    preg_match('#^/join/([A-Za-z0-9]{6})$#', (string) $intendedPath, $invite);

    return Inertia::render('Welcome', [
        'inviteCode' => isset($invite[1]) ? strtoupper($invite[1]) : null,
    ]);
})->name('home');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/game.php';
