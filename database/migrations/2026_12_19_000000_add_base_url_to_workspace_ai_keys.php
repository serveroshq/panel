<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_ai_keys', function (Blueprint $table) {
            $table->string('base_url', 255)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_ai_keys', function (Blueprint $table) {
            $table->dropColumn('base_url');
        });
    }
};
