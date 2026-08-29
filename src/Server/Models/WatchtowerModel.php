<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Models;

use Illuminate\Database\Eloquent\Model;

abstract class WatchtowerModel extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('watchtower.server.connection') ?? $this->connection;
    }
}
