<?php

use Illuminate\Support\Facades\Route;

test('password confirmation is unavailable', function () {
    expect(Route::has('password.confirm'))->toBeFalse();
});
