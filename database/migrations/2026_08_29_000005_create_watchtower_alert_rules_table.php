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
        Schema::connection($this->getConnection())->create('watchtower_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('watchtower_projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('environment')->nullable();
            $table->string('min_level')->default('error');
            $table->unsignedInteger('threshold_count')->nullable();
            $table->unsignedInteger('threshold_window_seconds')->nullable();
            $table->json('targets');
            $table->unsignedInteger('cooldown_seconds')->default(900);
            $table->timestamp('muted_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['project_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('watchtower_alert_rules');
    }
};
