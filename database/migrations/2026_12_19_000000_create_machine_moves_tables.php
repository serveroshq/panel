<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Moving a machine's apps onto another machine: each app deployed
        // there again, its data sent over and restored, then a switch-over.
        // The source is never changed until the switch, and never deleted.
        Schema::create('machine_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('to_machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // copying, ready, switching, switched, completed, rolled_back, cancelled or failed
            $table->string('state', 20)->index();
            // 1 while copying, 2 for the final copy at the switch-over
            $table->unsignedTinyInteger('round')->default(1);
            $table->text('error')->nullable();
            // The key the source sends data with, allowed in on the
            // destination's backup receiver only while the move needs it.
            $table->text('private_key')->nullable();
            $table->text('public_key')->nullable();
            $table->string('host_key', 1000)->nullable();
            // setting_up, ready, failed or revoked
            $table->string('receiver_state', 20)->nullable();
            $table->uuid('receiver_job_id')->nullable()->index();
            // Source containers stopped for the switch-over: [{key, job}]
            $table->json('stopped')->nullable();
            // Ports ServerOS opened on the source, opened on the destination too: [{rule, state, job, error}]
            $table->json('firewall')->nullable();
            // The source's Cloudflare Tunnel, moved at the switch-over: {state, job}
            $table->json('tunnel')->nullable();
            $table->timestamp('switched_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['from_machine_id', 'state']);
        });

        Schema::create('machine_move_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_move_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            $table->string('source', 10);
            // deploying, deployed, ready, deploying_domains, switched or failed
            $table->string('state', 20);
            $table->uuid('deploy_job_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['machine_move_id', 'name']);
        });

        Schema::create('machine_move_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_move_app_id')->constrained()->cascadeOnDelete();
            // The source container's inventory key and name
            $table->string('service', 255);
            $table->string('container', 255);
            // pg_dumpall, mysqldump or tar
            $table->string('method', 20);
            // waiting, sending, sent, restoring, copied or failed
            $table->string('state', 20);
            $table->unsignedTinyInteger('round')->default(1);
            $table->uuid('backup_job_id')->nullable()->index();
            $table->uuid('restore_job_id')->nullable()->index();
            $table->string('snapshot', 80)->nullable();
            $table->string('received', 500)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_move_copies');
        Schema::dropIfExists('machine_move_apps');
        Schema::dropIfExists('machine_moves');
    }
};
