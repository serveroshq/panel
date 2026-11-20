<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A machine a package runs for the workspace (ServerOS's own agent
 * machines): it doesn't use the workspace's machine allowance. Its join
 * token says so, and can name the machine record it joins as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('hosted_by', 40)->nullable()->index();
        });
        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->string('hosted_by', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropIndex(['hosted_by']);
            $table->dropColumn('hosted_by');
        });
        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->dropColumn('hosted_by');
        });
    }
};
