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
        Schema::connection($this->getConnection())->create('watchtower_issue_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('watchtower_projects')->cascadeOnDelete();
            $table->string('fingerprint', 32);
            $table->text('title');
            $table->string('platform')->nullable();
            $table->string('level')->default('error');
            $table->string('status')->default('unresolved');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('event_count')->default(0);
            $table->unsignedBigInteger('user_count')->default(0);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('last_status_change_at')->nullable();
            $table->string('resolved_in_release')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'status', 'last_seen_at']);
            $table->index(['project_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('watchtower_issue_groups');
    }
};
