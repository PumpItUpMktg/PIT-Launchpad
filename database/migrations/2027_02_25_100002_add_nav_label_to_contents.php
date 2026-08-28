<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grouped navigation — the short header label. A child page under a hub repeats the hub's terms in its title
 * ("Sump Pumps" › "Sump Pump Installation"), which reads as noise under a heading that already says it.
 * `nav_label` is a SEPARATE, header-only short label — never the title, H1, or meta title, so shortening the
 * menu never touches anything that ranks. It's auto-seeded by the deriver (strip the hub's terms from the
 * child) and operator-overridable.
 *
 * `nav_label_confirmed` distinguishes an operator's deliberate value from an auto-seeded one: the seeder only
 * writes rows where it's false, so re-seeding (a title/hub change) never clobbers an operator override. Both
 * additive; nothing reads `nav_label` for the live header yet (that wiring is the next slice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('nav_label')->nullable()->after('nav_order');
            $table->boolean('nav_label_confirmed')->default(false)->after('nav_label');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn(['nav_label', 'nav_label_confirmed']);
        });
    }
};
