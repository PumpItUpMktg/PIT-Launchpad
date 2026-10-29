<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Publishing\Forms\LeadFormResolver;
use Database\Factories\LeadFormFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named lead-capture form in the site's library — any provider's iframe embed (Joby, GHL, Jotform …),
 * pasted verbatim. A form is assigned to the owners of pages: a {@see Service} (its service page), a
 * {@see Location} (the office hub + every town page it serves), or the site default
 * ({@see ConversionConfig::$default_lead_form_id}). {@see LeadFormResolver} picks
 * the one a page renders; the plugin's [lp_form] shows whatever embed rides the page's meta blob.
 *
 * @property string $id
 * @property string $site_id
 * @property string $name
 * @property string $embed
 */
class LeadForm extends Model
{
    /** @use HasFactory<LeadFormFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
