<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the directory fields the new operator surface needs (§ Citations): a URL-safe `slug`
 * for routing/anchoring, the human `homepage_url`, and `is_submittable` (can we submit this programmatically /
 * by work order, vs. it needs the client). authority_tier stays the numeric 1–5 scoring weight; the UI reads
 * its high/medium/low band via the model accessor. Existing rows get a slug backfilled from the domain and
 * `is_submittable` derived from the submission method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directories', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
            $table->string('homepage_url')->nullable()->after('domain');
            $table->boolean('is_submittable')->default(true)->after('submission_url');
        });

        // Backfill: slug from domain; a paid/manual method that requires client action is not submittable by us.
        foreach (DB::table('directories')->get(['id', 'domain', 'submission_method', 'requires_client_action']) as $row) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $row->domain)), '-');
            DB::table('directories')->where('id', $row->id)->update([
                'slug' => $slug,
                'is_submittable' => ! (bool) $row->requires_client_action,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('directories', function (Blueprint $table): void {
            $table->dropColumn(['slug', 'homepage_url', 'is_submittable']);
        });
    }
};
