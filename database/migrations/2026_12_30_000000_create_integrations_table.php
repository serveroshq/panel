<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('label', 80);
            $table->text('credentials');
            $table->string('fingerprint', 64);
            $table->json('meta')->nullable();
            $table->string('status', 20)->default('connected');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'provider']);
            $table->unique(['workspace_id', 'provider', 'fingerprint']);
        });

        Schema::create('integration_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('daemon_enrolment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('server_id', 64);
            $table->string('name', 120);
            $table->json('details')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'server_id']);
        });

        Schema::create('integration_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('server_id', 64)->nullable();
            $table->string('state', 20)->default('queued');
            $table->string('step', 40)->nullable();
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->string('action_id', 64)->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('step_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'state']);
            $table->index(['machine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_operations');
        Schema::dropIfExists('integration_servers');
        Schema::dropIfExists('integrations');
    }
};
