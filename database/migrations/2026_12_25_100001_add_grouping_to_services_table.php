<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            // Author-declared grouping (services-entry): a sub-service nests under its parent so the
            // hub/spoke structure is deterministic instead of AI-guessed. Deferred-FK (self-ref, §1
            // convention — indexed ULID, not a DB constraint) so it survives the parent being reordered.
            $table->char('parent_service_id', 26)->nullable()->index()->after('id');
            // How a child is treated: 'page' (its own spoke URL under the hub) or 'section' (folded into
            // the parent's page). Default 'section' — sub-services fold in unless deliberately promoted,
            // so the common outcome is a rich single page, not a thin extra URL. Meaningless on a top-level
            // service (implicitly a page). Maps to SpokeGranularity at build.
            $table->string('page_treatment')->default('section')->after('silo_role');
            // Manual order within a group / among top-level services.
            $table->integer('group_order')->nullable()->after('page_treatment');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['parent_service_id', 'page_treatment', 'group_order']);
        });
    }
};
