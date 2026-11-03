<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-site settings for the cold outreach pipeline: which Instantly campaign warms contacts, which
 * Brevo list receives them, and the thresholds for "deliverable" and "warm". API keys are not here —
 * they live on the site's Bird / Instantly / Brevo `Connection` rows (encrypted).
 *
 * @property string $id
 * @property string $site_id
 * @property bool $enabled
 * @property string|null $instantly_campaign_id
 * @property int|null $brevo_list_id
 * @property int $min_confidence
 * @property bool $accept_neutral
 * @property bool $warm_on_reply
 * @property bool $warm_on_interested
 * @property int $warm_min_opens
 * @property bool $auto_promote
 */
class ColdOutreachConfig extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => false,
        'min_confidence' => 70,
        'accept_neutral' => true,
        'warm_on_reply' => true,
        'warm_on_interested' => true,
        'warm_min_opens' => 0,
        'auto_promote' => true,
    ];

    /** The site's settings row, or unsaved defaults when the site has none yet. */
    public static function forSite(string $siteId): self
    {
        return self::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->first()
            ?? new self(['site_id' => $siteId]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'brevo_list_id' => 'integer',
            'min_confidence' => 'integer',
            'accept_neutral' => 'boolean',
            'warm_on_reply' => 'boolean',
            'warm_on_interested' => 'boolean',
            'warm_min_opens' => 'integer',
            'auto_promote' => 'boolean',
        ];
    }
}
