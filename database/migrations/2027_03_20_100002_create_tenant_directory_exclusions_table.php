<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — a tenant's decision that a directory is not relevant to it (§ Citations). Set at the
 * TENANT level and applies to every location that tenant owns; there is no per-location exclusion. An
 * exclusion removes the directory from eligibility for all of the tenant's locations, so "Mark not relevant"
 * on one listing clears it everywhere. Unique per (site, directory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_directory_exclusions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('directory_id')->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->foreignUlid('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('excluded_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'directory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_directory_exclusions');
    }
};
