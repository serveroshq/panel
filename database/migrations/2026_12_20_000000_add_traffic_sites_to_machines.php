<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // The sites the machine's own nginx and Apache configs serve, as
            // the daemon last reported them while counting their traffic.
            $table->json('traffic_sites')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('traffic_sites');
        });
    }
};
