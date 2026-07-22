<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            // The owner's explicit "always build a page for this service" — the coverage guarantee that
            // wins over the demand-first structure. A stated service the owner really performs but that
            // carries thin search demand (mold testing, radon, water-damage cleanup) is force_page'd and
            // pinned to a topic (forced_silo); ServicePageGuarantee re-materializes its own_page spoke on
            // every (re)build so it can never silently drop out of the plan.
            $table->boolean('force_page')->default(false)->after('structure_home_flagged');
            $table->string('forced_silo')->nullable()->after('force_page');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['force_page', 'forced_silo']);
        });
    }
};
