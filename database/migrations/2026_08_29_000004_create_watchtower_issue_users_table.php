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
        Schema::connection($this->getConnection())->create('watchtower_issue_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('watchtower_issue_groups')->cascadeOnDelete();
            $table->string('user_id_hash', 64);
            $table->timestamp('first_seen_at');

            $table->unique(['group_id', 'user_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('watchtower_issue_users');
    }
};
