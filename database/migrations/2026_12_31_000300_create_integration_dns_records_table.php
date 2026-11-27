<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service', 63);
            $table->string('zone_id', 64);
            $table->string('zone_name', 253);
            $table->string('hostname', 253);
            $table->string('record_id', 64)->nullable();
            $table->string('type', 10)->nullable();
            $table->string('content', 255)->nullable();
            $table->boolean('proxied')->default(false);
            $table->boolean('created_record')->default(false);
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->text('warning')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'hostname']);
            $table->index(['machine_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_dns_records');
    }
};
