<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each container's image against its registry: whether the tag it
        // runs points at a newer image there now.
        Schema::create('container_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('container', 255);
            $table->string('service_key', 160);
            $table->string('image', 512);
            $table->string('image_id', 80)->nullable();
            // current, available, local (built here or pinned), unknown (the registry didn't say)
            $table->string('state', 16);
            $table->string('remote_digest', 80)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('available_since')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_job_id', 36)->nullable();
            $table->timestamps();
            $table->unique(['machine_id', 'container']);
            $table->index(['state', 'available_since']);
        });

        // Known vulnerabilities in the packages an image ships with, that
        // the image's own distribution has fixed: a newer image (or a
        // rebuild) takes them away.
        Schema::create('image_vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vulnerability_id')->constrained()->cascadeOnDelete();
            $table->string('image_id', 80);
            $table->string('image', 512);
            $table->string('source', 200);
            $table->string('installed', 128)->nullable();
            $table->string('fixed_in', 128)->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['machine_id', 'image_id', 'vulnerability_id', 'source'], 'image_vulns_unique');
            $table->index(['machine_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_vulnerabilities');
        Schema::dropIfExists('container_images');
    }
};
