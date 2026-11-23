<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Alerts sent to a Telegram chat by the workspace's own bot. The
            // token is encrypted, and never sent back to a browser or an AI.
            $table->string('alert_telegram_chat', 64)->nullable();
            $table->text('alert_telegram_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['alert_telegram_chat', 'alert_telegram_token']);
        });
    }
};
