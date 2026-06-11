<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §7b(c) — the operator's engine-side kit→Elementor-template mapping, per tenant.
 * One current row per (site, kit); `version` increments on every remap so the
 * choice is auditable, and the table is portfolio-visible (queried across sites
 * by the operator cockpit). `template_id` is the WordPress elementor_library post
 * id resolved live from the /templates inventory; `template_title` is a display
 * cache. The §2 push reads this to stamp the resolved template on the /content
 * blob — explicit mapping wins over the kit's elementor_template_ref suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_template_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('kit');
            $table->unsignedBigInteger('template_id');
            $table->string('template_title')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['site_id', 'kit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_template_mappings');
    }
};
