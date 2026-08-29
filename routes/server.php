<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Server\Http\Controllers\IngestController;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\EnvelopeKeyAuth;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\IngestRateLimit;
use Phattarachai\WatchtowerLaravel\Server\Http\Middleware\IngestSizeLimit;

$prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');
$prefix = $prefix === '' ? '' : $prefix.'/';

Route::post($prefix.'api/{project}/envelope', IngestController::class)
    ->whereNumber('project')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware([IngestSizeLimit::class, EnvelopeKeyAuth::class, IngestRateLimit::class])
    ->name('watchtower.server.ingest.envelope');
