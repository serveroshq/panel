<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workflows ServerOS keeps for its own features, like an agent task's:
        // not listed, counted, searched or editable as the workspace's own.
        Schema::table('workflows', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('enabled');
        });

        DB::table('workflows')->whereIn('id', DB::table('agent_tasks')->select('workflow_id'))->update(['internal' => true]);
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};
