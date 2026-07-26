<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §B slice 2 — the blog↔town join. A post is tagged with every town it references, so the town becomes
 * a queryable dimension: the WP `lp_area` taxonomy is assigned from these rows, and location pages list
 * their town's posts from them. Keyed on the NORMALIZED town NAME (not a CoverageArea FK) so the link
 * survives a coverage/silo rebuild — the whole point of §B is that these references don't dangle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_towns', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('content_id', 26);
            $table->char('site_id', 26);
            $table->string('town');          // normalized key: lower-case, no ", ST" suffix
            $table->string('town_display');  // the label WordPress shows / the term name
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            $table->unique(['content_id', 'town']);
            $table->index(['site_id', 'town']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_towns');
    }
};
