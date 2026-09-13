<?php

use Illuminate\Support\Facades\Route;

test('password and two factor settings are unavailable', function () {
    expect(Route::has('security.edit'))->toBeFalse()
        ->and(Route::has('user-password.update'))->toBeFalse()
        ->and(config('fortify.features'))->toBe([]);
});
