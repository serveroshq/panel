<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Each agent in a task is a run of this one-step workflow, which
            // never shows on the Workflows page.
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service');
            $table->string('title', 160);
            $table->text('prompt');
            $table->string('agent', 20)->default('claude');
            $table->string('model', 80)->nullable();
            $table->string('repo', 200)->nullable();
            $table->string('branch', 200)->default('main');
            $table->boolean('pull_request')->default(true);
            $table->unsignedTinyInteger('agents')->default(1);
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
    }
};
