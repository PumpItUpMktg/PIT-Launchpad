<?php

use App\Support\BusinessHours;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair location hours that the Filament repeater persisted numeric-keyed
 * (`[0 => "24h", …]`) before the round-trip was pinned. `BusinessHours::normalize`
 * coerces any persisted shape back to the day-keyed map; it is a no-op for rows
 * already in the correct shape, so this is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('locations')->select('id', 'hours')->cursor() as $row) {
            if ($row->hours === null) {
                continue;
            }

            $decoded = json_decode((string) $row->hours, true);
            if (! is_array($decoded)) {
                continue;
            }

            DB::table('locations')->where('id', $row->id)->update([
                'hours' => json_encode(BusinessHours::normalize($decoded)),
            ]);
        }
    }

    public function down(): void
    {
        // One-way data repair; no inverse.
    }
};
