<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('mcp_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('via', 16);
            $table->json('scopes')->nullable();
            $table->string('kind', 32);
            $table->longText('params');
            $table->json('selector')->nullable();
            $table->string('batch', 8)->nullable();
            $table->unsignedInteger('batch_size');
            $table->boolean('stop_on_failure')->default(true);
            $table->unsignedInteger('max_failures')->default(0);
            $table->unsignedInteger('failures_before')->default(0);
            $table->unsignedInteger('wait_healthy_seconds')->default(0);
            $table->unsignedInteger('pause_between_seconds')->default(0);
            $table->string('state', 16)->index();
            $table->string('paused_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operation_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('machine_name');
            $table->unsignedInteger('position');
            $table->string('state', 16);
            $table->uuid('job_id')->nullable();
            $table->string('outcome', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('healthy_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['operation_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_machines');
        Schema::dropIfExists('operations');
    }
};
