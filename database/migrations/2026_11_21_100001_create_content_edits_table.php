<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit-capture (§7) — the quality signal. Every operator correction in the proof step is recorded
 * with the ORIGINAL generated text (stored before the edit overwrites it — non-retrofittable), the
 * edited version, a one-tap reason tag (off-base / off-brand / preference), and coordinates (field,
 * page, silo, site, user). The reason tag maps straight to an action: off-base → fix generation/
 * grounding, off-brand → fix brand voice, preference → ignore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_edits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            $table->foreignUlid('content_id')->index();
            $table->foreignUlid('silo_id')->nullable()->index();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('field');               // slot:<key> | body | seo:<key>
            $table->string('reason');              // EditReason: off_base | off_brand | preference
            $table->text('original')->nullable();  // the generated text, captured before overwrite
            $table->text('edited')->nullable();    // the operator's correction
            $table->timestamps();

            $table->index(['site_id', 'reason']);
            $table->index(['silo_id', 'reason']);  // the read-across view groups by silo × reason
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_edits');
    }
};
