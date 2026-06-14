<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiteBrandingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $site_id
 * @property array<string, mixed>|null $logo_set
 * @property array<string, mixed>|null $palette
 * @property array<string, mixed>|null $typography
 * @property string|null $imagery_style
 * @property array<string, mixed>|null $social_handles
 * @property string|null $entity_type
 * @property array<int, string>|null $same_as
 * @property array<string, mixed>|null $canonical_nap
 */
class SiteBranding extends Model
{
    /** @use HasFactory<SiteBrandingFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $table = 'site_branding';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'logo_set' => 'array',
            'palette' => 'array',
            'typography' => 'array',
            'social_handles' => 'array',
            'same_as' => 'array',
            'canonical_nap' => 'array',
        ];
    }
}
