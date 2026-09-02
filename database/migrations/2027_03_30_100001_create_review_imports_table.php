<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The progress record for a bulk review import (Review Capture §10). A CSV / XLSX / Google-Sheet upload is
 * parsed and inserted in a QUEUED job (a 5,000-row sheet never runs in a web request), and this row tracks it:
 * counts + the skipped-row report (dedupe hits, invalid rows), never silently merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            $table->ulid('created_by_user_id')->nullable();

            $table->string('status')->default('pending'); // pending | processing | complete | failed
            $table->string('source');                     // csv | xlsx | sheet
            $table->string('import_source')->nullable();   // default per-upload label (google | facebook | angi | ...)
            $table->string('filename')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->jsonb('skipped_rows')->nullable();     // [{row, reason}]
            $table->string('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_imports');
    }
};
