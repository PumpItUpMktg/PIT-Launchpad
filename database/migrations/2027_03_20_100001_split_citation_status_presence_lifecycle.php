<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the single `state` enum on citation_statuses into the two independent axes the module actually needs
 * (§ Citations): `presence` (scanner-owned — is the listing out there, and is it correct) and `lifecycle`
 * (CitationLifecycle-owned — what work have we done). Collapsing them made a verified listing that later goes
 * wrong unrepresentable: the scan couldn't record the new mismatch without clobbering the verified state. With
 * two axes a citation can be lifecycle=verified AND presence=present_mismatch at once.
 *
 * Two boolean columns preserve the attribution-safety states that were folded into the old enum:
 * `covered_by_sibling` (a one-per-business directory a sibling satisfies) and `needs_review` (attribution too
 * ambiguous to auto-decide). `unresolved_scans` is dropped — stall is now a lifecycle outcome, not a scan one.
 *
 * Backfill-then-drop: correct whether the table holds 0 or N rows. Rows in a lifecycle-only state (submitted /
 * rejected / stalled) have no recoverable presence, so they backfill to `unknown` and are repopulated on the
 * next scan (coverage may dip slightly until then — by design, not a gap).
 */
return new class extends Migration
{
    /** old single state => [presence, lifecycle, covered_by_sibling, needs_review] */
    private const MAP = [
        'listed_correct' => ['present_match', 'none', 0, 0],
        'needs_fix' => ['present_mismatch', 'none', 0, 0],
        'not_listed' => ['absent', 'none', 0, 0],
        'unverified' => ['present_match', 'none', 0, 0],      // scan-found, NAP unconfirmed
        'duplicate' => ['present_mismatch', 'none', 0, 0],
        'blocked_client_action' => ['absent', 'none', 0, 0],
        'sibling_listing' => ['absent', 'none', 0, 0],
        'covered_by_sibling' => ['absent', 'none', 1, 0],
        'ambiguous_review' => ['unknown', 'none', 0, 1],
        'submitted' => ['unknown', 'submitted', 0, 0],
        'pending_verification' => ['unknown', 'submitted', 0, 0],
        'live' => ['present_match', 'verified', 0, 0],
        'fixed' => ['present_match', 'verified', 0, 0],
        'rejected' => ['unknown', 'rejected', 0, 0],
        'stalled' => ['unknown', 'stalled', 0, 0],
    ];

    public function up(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->string('presence')->default('unknown')->after('directory_id');
            $table->string('lifecycle')->default('none')->after('presence');
            $table->boolean('covered_by_sibling')->default(false)->after('lifecycle');
            $table->boolean('needs_review')->default(false)->after('covered_by_sibling');
        });

        foreach (self::MAP as $state => [$presence, $lifecycle, $covered, $review]) {
            DB::table('citation_statuses')->where('state', $state)->update([
                'presence' => $presence,
                'lifecycle' => $lifecycle,
                'covered_by_sibling' => $covered,
                'needs_review' => $review,
            ]);
        }

        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->dropColumn(['state', 'unresolved_scans']);
        });
    }

    public function down(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->string('state')->default('not_listed')->after('directory_id');
            $table->unsignedInteger('unresolved_scans')->default(0);
        });

        // Best-effort inverse: presence+lifecycle collapse back to the closest single state.
        DB::table('citation_statuses')->where('lifecycle', 'verified')->update(['state' => 'live']);
        DB::table('citation_statuses')->where('lifecycle', 'submitted')->update(['state' => 'submitted']);
        DB::table('citation_statuses')->where('lifecycle', 'rejected')->update(['state' => 'rejected']);
        DB::table('citation_statuses')->where('lifecycle', 'stalled')->update(['state' => 'stalled']);
        DB::table('citation_statuses')->where('lifecycle', 'none')->where('presence', 'present_match')->update(['state' => 'listed_correct']);
        DB::table('citation_statuses')->where('lifecycle', 'none')->where('presence', 'present_mismatch')->update(['state' => 'needs_fix']);
        DB::table('citation_statuses')->where('lifecycle', 'none')->where('presence', 'absent')->update(['state' => 'not_listed']);
        DB::table('citation_statuses')->where('covered_by_sibling', true)->update(['state' => 'covered_by_sibling']);
        DB::table('citation_statuses')->where('needs_review', true)->update(['state' => 'ambiguous_review']);

        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->dropColumn(['presence', 'lifecycle', 'covered_by_sibling', 'needs_review']);
        });
    }
};
