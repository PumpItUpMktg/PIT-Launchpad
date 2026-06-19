<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiloBlueprintFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $client_approved_at the §7c client sign-off on the proposed page plan
 * @property string|null $client_approved_by the client user who signed off
 * @property array<string, mixed>|null $prune_draft
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

    /** Whether the client has signed off on the proposed page plan (§7c). */
    public function isClientApproved(): bool
    {
        return $this->client_approved_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seed' => 'array',
            'transcript' => 'array',
            'confirmed_at' => 'datetime',
            'client_approved_at' => 'datetime',
            'prune_draft' => 'array',
        ];
    }
}
