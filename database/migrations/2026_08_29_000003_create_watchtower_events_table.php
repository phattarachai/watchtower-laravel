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
        Schema::connection($this->getConnection())->create('watchtower_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('group_id')->constrained('watchtower_issue_groups')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('watchtower_projects')->cascadeOnDelete();
            $table->string('environment')->nullable();
            $table->string('release')->nullable();
            $table->timestamp('received_at');
            $table->string('level')->default('error');
            $table->string('sdk_name')->nullable();
            $table->string('user_id_hash', 64)->nullable();
            $table->json('payload');

            $table->index(['project_id', 'received_at']);
            $table->index(['group_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('watchtower_events');
    }
};
