<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead forms as a per-site library (one named iframe embed per form — a Joby / GHL / Jotform form the
 * tenant built per product or per office) and the two owners a page can inherit one from: a Service
 * (every service page) and a Location (the office hub + every town it serves). The site default is the
 * form the home page and the rest use; the legacy single paste (`conversion_configs.form_embed`) stays
 * as the last fallback so nothing changes until forms are assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('embed');
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->foreignUlid('lead_form_id')->nullable()->after('silo_role')->constrained('lead_forms')->nullOnDelete();
        });
        Schema::table('locations', function (Blueprint $table): void {
            $table->foreignUlid('lead_form_id')->nullable()->constrained('lead_forms')->nullOnDelete();
        });
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->foreignUlid('default_lead_form_id')->nullable()->after('form_embed')->constrained('lead_forms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_lead_form_id');
        });
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_form_id');
        });
        Schema::table('services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_form_id');
        });
        Schema::dropIfExists('lead_forms');
    }
};
