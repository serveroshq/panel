<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_token_id')->constrained()->cascadeOnDelete();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('created_at')->index();
            $table->unique(['mcp_token_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
