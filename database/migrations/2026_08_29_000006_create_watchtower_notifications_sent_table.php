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
        Schema::connection($this->getConnection())->create('watchtower_notifications_sent', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('watchtower_alert_rules')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('watchtower_issue_groups')->cascadeOnDelete();
            $table->string('kind');
            $table->timestamp('sent_at');
            $table->unsignedInteger('recipient_count')->default(0);

            $table->index(['rule_id', 'group_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('watchtower_notifications_sent');
    }
};
