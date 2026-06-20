<?php

namespace App\Models;

use App\Enums\SetupStep;
use Database\Factories\SetupStateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-site guided-setup progress: where the operator is (`current_step`) and the per-step
 * completion gates that unlock the next step, plus Step 4's build-config toggles. One row per
 * Site (read across tenants by the operator, so no site scope — keyed explicitly by site).
 *
 * @property string $id
 * @property string $site_id
 * @property int $current_step
 * @property bool $services_done
 * @property bool $deps_ready WordPress connected + verified + prepped (step 2)
 * @property bool $brand_pushed brand kit pushed to the prepped site (step 3)
 * @property bool $territory_done
 * @property bool $structure_finalized
 * @property string|null $structure_status null|building|ready|failed (Step 3 engine-on-entry)
 * @property bool $approved
 * @property bool $launched
 * @property bool $localize
 * @property int $town_page_pace
 * @property bool $fresh_content
 * @property array<string, bool>|null $standard_pages accepted optional standard pages (type => bool)
 * @property array<string, bool>|null $intake_flags interim intake gates (financing / team)
 * @property string|null $build_status the Build phase (null|building|live)
 */
class SetupState extends Model
{
    /** @use HasFactory<SetupStateFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Whether a given step's completion gate is set. */
    public function isComplete(SetupStep $step): bool
    {
        $flag = $step->completionFlag();

        return $flag !== null && (bool) $this->{$flag};
    }

    /** The step the operator is currently on. */
    public function step(): SetupStep
    {
        return SetupStep::tryFrom($this->current_step) ?? SetupStep::Business;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'services_done' => 'boolean',
            'deps_ready' => 'boolean',
            'brand_pushed' => 'boolean',
            'territory_done' => 'boolean',
            'structure_finalized' => 'boolean',
            'approved' => 'boolean',
            'launched' => 'boolean',
            'localize' => 'boolean',
            'town_page_pace' => 'integer',
            'fresh_content' => 'boolean',
            'standard_pages' => 'array',
            'intake_flags' => 'array',
        ];
    }
}
