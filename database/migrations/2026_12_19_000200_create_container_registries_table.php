<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A workspace's logins for container registries (Docker Hub, GHCR, a
        // self-hosted one), sent only with deploys that pull from one. The
        // password is encrypted and never shown again.
        Schema::create('container_registries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('host', 253);
            $table->string('username');
            $table->text('password');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_registries');
    }
};
