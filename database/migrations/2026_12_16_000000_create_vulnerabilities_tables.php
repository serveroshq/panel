<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Known vulnerabilities (CVEs), from the free feeds security:cves
        // reads: OSV (Ubuntu, Debian, AlmaLinux and Rocky's own trackers),
        // CISA's Known Exploited list, NVD and FIRST's EPSS. One row per CVE,
        // or per advisory when it has no CVE.
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->string('cve', 80)->unique();
            $table->string('title', 300)->nullable();
            $table->text('summary')->nullable();
            // critical, high, medium, low or unknown
            $table->string('severity', 10)->default('unknown')->index();
            // whose rating that is: distro, nvd or cvss (a vector OSV gave)
            $table->string('severity_by', 10)->nullable();
            $table->decimal('cvss', 3, 1)->nullable();
            // FIRST's chance it's exploited in the next 30 days, 0 to 1
            $table->decimal('epss', 6, 5)->nullable();
            $table->boolean('exploited')->default(false)->index();
            $table->date('exploited_since')->nullable();
            $table->boolean('ransomware')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('sources')->nullable();
            $table->json('advisories')->nullable();
            $table->json('references')->nullable();
            $table->json('products')->nullable();
            $table->timestamp('nvd_checked_at')->nullable();
            $table->timestamp('epss_checked_at')->nullable();
            // Posted to the public Discord feed
            $table->timestamp('announced_at')->nullable();
            $table->timestamps();
        });

        // A vulnerability on a machine that an update waiting on it fixes,
        // by source package (openssl covers libssl3t64 and openssl).
        Schema::create('machine_vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vulnerability_id')->constrained()->cascadeOnDelete();
            $table->string('source', 200);
            $table->json('packages');
            $table->string('installed', 128)->nullable();
            $table->string('fixed_by', 128)->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['machine_id', 'vulnerability_id', 'source']);
            $table->index(['resolved_at', 'notified_at']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('vulns_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('vulns_scanned_at');
        });
        Schema::dropIfExists('machine_vulnerabilities');
        Schema::dropIfExists('vulnerabilities');
    }
};
