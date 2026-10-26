<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('watchtower.server.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('watchtower_projects', function (Blueprint $table): void {
            $table->string('mcp_token_hash', 64)->nullable()->unique()->after('public_key');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('watchtower_projects', function (Blueprint $table): void {
            $table->dropUnique(['mcp_token_hash']);
            $table->dropColumn('mcp_token_hash');
        });
    }
};
