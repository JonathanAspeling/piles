<?php

use Illuminate\Support\Facades\DB;

test('sqlite only syncs the disk at checkpoints, not on every commit', function () {
    // PRAGMA synchronous: 1 is NORMAL, 2 is FULL.
    expect(DB::selectOne('pragma synchronous')->synchronous)->toBe(1);
});
