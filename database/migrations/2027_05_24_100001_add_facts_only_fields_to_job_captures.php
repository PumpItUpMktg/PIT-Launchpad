<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * § Job Capture — previous jobs imported with no description are written up from facts only (service,
 * town, season), each from a rotating angle, and checked against earlier write-ups so no two read alike.
 * `import_key` is the source system's id so a re-import never duplicates a job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->boolean('facts_only')->default(false);          // the write-up was composed from facts, not tech notes
            $table->string('write_up_angle', 40)->nullable();        // which angle the facts-only write-up took
            $table->ulid('similar_to_job_id')->nullable();           // flagged: reads too like this earlier job — blocks approve until edited / re-enhanced
            $table->string('import_key')->nullable();                // the CRM / source id of an imported row
            $table->index(['site_id', 'import_key']);
        });
    }

    public function down(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'import_key']);
            $table->dropColumn(['facts_only', 'write_up_angle', 'similar_to_job_id', 'import_key']);
        });
    }
};
