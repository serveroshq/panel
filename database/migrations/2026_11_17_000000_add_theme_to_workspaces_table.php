<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('theme_css')->nullable();
            $table->text('theme_draft_css')->nullable();
            $table->foreignId('theme_draft_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('theme_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theme_draft_user_id');
            $table->dropColumn(['theme_css', 'theme_draft_css', 'theme_published_at']);
        });
    }
};
