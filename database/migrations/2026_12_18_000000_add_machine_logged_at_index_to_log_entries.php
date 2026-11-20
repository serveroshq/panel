<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * logs:prune deletes each workspace's old lines with machine_id in (...) and
 * logged_at < cutoff. Without this index Postgres gathered every old line on
 * the server for each workspace, every hour. On Postgres it's built without
 * locking the table, since log_entries can be large.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'log_entries_machine_id_logged_at_index';

    public function up(): void
    {
        if (Schema::hasIndex('log_entries', self::INDEX)) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create index concurrently if not exists '.self::INDEX.' on log_entries (machine_id, logged_at)');

            return;
        }

        Schema::table('log_entries', function (Blueprint $table) {
            $table->index(['machine_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
