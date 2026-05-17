<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Http\Controllers\RelayController;

$path = config('watchtower.relay.path', '/api/watchtower-relay');

Route::post($path, RelayController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('watchtower.relay');
