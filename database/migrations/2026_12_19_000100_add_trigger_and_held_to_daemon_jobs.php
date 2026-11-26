<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            // What started it, when it wasn't a person: "push" for a push to
            // the app's branch. Only those are ever dropped for a newer one.
            $table->string('trigger', 16)->nullable();
            // Kept in the panel, not sent yet: a push deploy waiting for the
            // deploy before it to finish.
            $table->boolean('held')->default(false);
            $table->index(['machine_id', 'service', 'held']);
        });
    }

    public function down(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->dropIndex(['machine_id', 'service', 'held']);
            $table->dropColumn(['trigger', 'held']);
        });
    }
};
