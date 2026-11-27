<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_deploy_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service', 63);
            // Only a hash of the token is kept: the address is shown once.
            $table->string('token_hash', 64)->unique();
            $table->string('hint', 8);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_trigger', 16)->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_deploy_hooks');
    }
};
