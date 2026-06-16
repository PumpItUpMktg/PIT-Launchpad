<?php

namespace App\Models;

use Database\Factories\PageConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-page USER-OWNED config layer — values the operator sets and AI never
 * authors (hero variant, form embed, phone/image overrides, market binding). The
 * composer reads this on every compose and re-injects it, so a repush preserves
 * these verbatim while the generated content (H1/body/FAQ) refreshes. One row per
 * page (content_id). Not site-scoped at the model level — the publish path reads it
 * by content_id directly (operator/job context).
 *
 * @property string $site_id
 * @property string $content_id
 * @property string $hero_variant
 * @property string|null $form_embed
 * @property string|null $phone_override
 * @property string|null $hero_image_override
 * @property string|null $market_ref
 */
class PageConfig extends Model
{
    /** @use HasFactory<PageConfigFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function usesFormHero(): bool
    {
        return $this->hero_variant === 'form';
    }
}
