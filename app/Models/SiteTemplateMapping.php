<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\SiteTemplateMappingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The operator's engine-side mapping of a pushed kit to a real Elementor template
 * on a tenant's site (§7b controls). One current row per (site, kit); `version`
 * increments on each remap. Site-scoped like the rest of §1 — operator/portfolio
 * reads cross tenants via withoutGlobalScope(SiteScope::class). The §2 push reads
 * `template_id` to stamp the resolved template on the /content blob.
 */
class SiteTemplateMapping extends Model
{
    /** @use HasFactory<SiteTemplateMappingFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
