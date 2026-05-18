<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('@watchtowerUser renders the three user-meta tags', function (): void {
    $html = Blade::render('@watchtowerUser');

    expect($html)
        ->toContain('<meta name="watchtower-user-id"')
        ->toContain('<meta name="watchtower-user-email"')
        ->toContain('<meta name="watchtower-user-name"');
});

it('@watchtowerUser falls back to empty content when no user is authenticated', function (): void {
    $html = Blade::render('@watchtowerUser');

    expect($html)
        ->toContain('content=""');
});
