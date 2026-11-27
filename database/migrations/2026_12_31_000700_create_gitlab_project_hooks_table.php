<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gitlab_project_hooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->string('project', 255);
            $table->unsignedBigInteger('hook_id');
            $table->text('secret');
            $table->timestamp('last_push_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'project']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gitlab_project_hooks');
    }
};
