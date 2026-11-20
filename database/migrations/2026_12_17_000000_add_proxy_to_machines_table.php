<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // The reverse proxy serving the machine's app domains (caddy,
            // nginx, apache, traefik or none), as its daemon reports it.
            $table->string('proxy', 16)->nullable()->after('daemon_version');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('proxy');
        });
    }
};
