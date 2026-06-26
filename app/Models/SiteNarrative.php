<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiteNarrativeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A site's brand-NARRATIVE intake — the words the Core-page composer grounds on (About story,
 * mission, values, Why-Choose-Us differentiators, team). Distinct from {@see SiteBranding} (visual
 * identity). 1:1 with a site; every field nullable so a page degrades on what's captured.
 *
 * @property string $site_id
 * @property string|null $story
 * @property string|null $mission
 * @property array<int, mixed>|null $values
 * @property array<int, mixed>|null $differentiators
 * @property array<int, mixed>|null $team
 */
class SiteNarrative extends Model
{
    /** @use HasFactory<SiteNarrativeFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'values' => 'array',
            'differentiators' => 'array',
            'team' => 'array',
        ];
    }
}
