<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiloBlueprintFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The confirmed silo blueprint — the directed-coverage spine produced by the owner
 * interview arc. Phase 2 expansion fills its spokes; Phase 4's prune confirms them.
 * `seed` records the SiloSeed snapshot it was built from.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $trade
 * @property array<string, mixed>|null $seed
 * @property list<array{role: string, text: string}>|null $transcript
 */
class SiloBlueprint extends Model
{
    /** @use HasFactory<SiloBlueprintFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<Spoke, $this> */
    public function spokes(): HasMany
    {
        return $this->hasMany(Spoke::class);
    }

    /**
     * The pillar spokes — one per silo grouping.
     *
     * @return HasMany<Spoke, $this>
     */
    public function pillars(): HasMany
    {
        return $this->spokes()->where('is_pillar', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seed' => 'array',
            'transcript' => 'array',
        ];
    }
}
