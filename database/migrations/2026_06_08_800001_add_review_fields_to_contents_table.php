<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // Operator reject reason, surfaced in the review queue.
            $table->text('reject_reason')->nullable();
            // Near-dup linkage for the review queue's near-dup flag (the matched
            // content + refresh-vs-new context). No DB FK — the §1 deferred-FK
            // pattern; populated by the §6a funnel's near-dup detection.
            $table->ulid('near_dup_of_content_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['reject_reason', 'near_dup_of_content_id']);
        });
    }
};
