<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            // What the task started from: a failed deploy, a machine's logs, a monitor.
            $table->json('context')->nullable()->after('prompt');
        });

        // Agents waiting for room on their machine, or for a quiet hour.
        Schema::create('agent_task_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('turn', 32);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('after_run_id')->nullable();
            $table->timestamp('not_before')->nullable();
            $table->boolean('quiet')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_task_queue');
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
