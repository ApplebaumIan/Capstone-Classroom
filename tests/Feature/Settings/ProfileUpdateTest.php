<?php

use Illuminate\Support\Facades\Route;

test('account settings are unavailable', function () {
    expect(Route::has('profile.edit'))->toBeFalse()
        ->and(Route::has('profile.update'))->toBeFalse()
        ->and(Route::has('profile.destroy'))->toBeFalse();
});
