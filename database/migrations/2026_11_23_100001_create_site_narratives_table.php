<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a site's brand-NARRATIVE intake lives — the inputs the Core/standard-page composer needs but
 * §1 never captured: the About story, mission, values, the Why-Choose-Us differentiators, the team.
 * Separate from visual branding (`site_branding`) — this is words, that is identity. 1:1 with a site.
 *
 * The composer grounds About / Why-Choose-Us / Home on these; a page whose REQUIRED narrative is
 * absent holds "needs intake" rather than fabricating (degrade by omission, never by invention).
 * Every field is nullable: a site can finalize before its narrative is captured, and each page
 * degrades on what it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_narratives', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('story')->nullable();          // the About / brand story narrative
            $table->text('mission')->nullable();        // what the brand commits to
            $table->json('values')->nullable();         // list of {title, description}
            $table->json('differentiators')->nullable(); // Why-Choose-Us: list of {title, description}
            $table->json('team')->nullable();           // list of {name, role, bio} (Team page, later)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_narratives');
    }
};
