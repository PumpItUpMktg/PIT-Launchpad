<?php

use App\Integrations\Usda\SoilDrainage;
use App\Local\Grounding\TownSoilSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a town's mapped area is underwater, and a repair for the rows written before we counted it.
 *
 * The survey's `Subaqueous` class is soil beneath a bay or estuary. A coastal town's boundary takes in
 * open water, so counting that seabed as ground made Brooklyn — whose largest mapped class is subaqueous
 * — come back "drains freely". It doesn't: most of what was measured is harbour.
 *
 * The stored `classes` payload still carries the raw shares, so the fix recomputes from it rather than
 * refetching: drainage shares over LAND only, the dominant class from land only, and a town with no land
 * left holding no claim at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('town_soil_drainage', function (Blueprint $table): void {
            $table->decimal('water_share', 5, 4)->nullable()->after('poorly_share');
        });

        foreach (DB::table('town_soil_drainage')->get(['id', 'classes']) as $row) {
            $classes = json_decode((string) $row->classes, true);
            if (! is_array($classes) || $classes === []) {
                continue;
            }

            $land = [];
            $water = 0.0;
            foreach ($classes as $class) {
                if (! is_array($class)) {
                    continue;
                }
                $name = trim((string) ($class['class'] ?? ''));
                $share = (float) ($class['share'] ?? 0);
                if ($name === SoilDrainage::WATER) {
                    $water += $share;

                    continue;
                }
                if ($name !== '') {
                    $land[$name] = ($land[$name] ?? 0.0) + $share;
                }
            }

            $landTotal = array_sum($land);
            if ($landTotal <= 0.0) {
                DB::table('town_soil_drainage')->where('id', $row->id)->update([
                    'dominant' => null, 'poorly_share' => null, 'water_share' => 1.0, 'classes' => json_encode([]),
                ]);

                continue;
            }

            arsort($land);
            $rescaled = [];
            $poorly = 0.0;
            foreach ($land as $name => $share) {
                $normalised = round($share / $landTotal, 4);
                $rescaled[] = ['class' => $name, 'share' => $normalised];
                if (in_array($name, TownSoilSync::POORLY, true)) {
                    $poorly += $normalised;
                }
            }

            DB::table('town_soil_drainage')->where('id', $row->id)->update([
                'dominant' => $rescaled[0]['class'],
                'poorly_share' => round($poorly, 4),
                'water_share' => round($water, 4),
                'classes' => json_encode($rescaled),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('town_soil_drainage', function (Blueprint $table): void {
            $table->dropColumn('water_share');
        });
    }
};
