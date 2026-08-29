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
        Schema::connection($this->getConnection())->create('watchtower_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('platform')->default('laravel');
            $table->string('public_key', 64)->unique();
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('watchtower_projects');
    }
};
