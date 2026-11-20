<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each site's traffic, counted by the daemon from the proxy's access
        // logs: a row a minute for two days, then a row an hour for 90 days.
        Schema::create('traffic_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('site', 120);
            $table->unsignedBigInteger('bucket');
            $table->unsignedInteger('seconds');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('status_2xx')->default(0);
            $table->unsignedBigInteger('status_3xx')->default(0);
            $table->unsignedBigInteger('status_4xx')->default(0);
            $table->unsignedBigInteger('status_5xx')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->float('p50_ms')->nullable();
            $table->float('p95_ms')->nullable();
            $table->unsignedBigInteger('visitors')->default(0);
            $table->text('paths')->nullable();
            $table->text('referrers')->nullable();
            // Minutes not yet counted in their hour's row.
            $table->boolean('rolled')->default(false);

            $table->unique(['machine_id', 'site', 'seconds', 'bucket'], 'traffic_rollups_site_bucket_unique');
            $table->index(['rolled', 'seconds'], 'traffic_rollups_rolled_index');
            $table->index(['seconds', 'bucket'], 'traffic_rollups_age_index');
        });

        Schema::table('machines', function (Blueprint $table) {
            // Whether the daemon counts visits from the proxy's access logs,
            // and the job that last changed it.
            $table->json('traffic')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('traffic');
        });
        Schema::dropIfExists('traffic_rollups');
    }
};
