<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The suite's authenticatable. The UI never touches the host's user model
 * beyond `$request->user()`, so a stand-in is the whole contract.
 */
final class WtUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
