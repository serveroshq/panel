<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('meta');
        });

        Schema::create('integration_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service', 120);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'machine_id', 'service']);
            $table->index(['machine_id', 'service']);
        });

        Schema::create('integration_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('open_key', 120)->nullable();
            $table->string('kind', 20);
            $table->foreignId('monitor_incident_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('external_id', 64)->nullable();
            $table->string('external_key', 40)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'open_key']);
            $table->index(['integration_id', 'key']);
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_incident_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->string('subject', 200);
            $table->json('payload')->nullable();
            $table->string('state', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'id']);
            $table->index(['state', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integration_incidents');
        Schema::dropIfExists('integration_apps');
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
