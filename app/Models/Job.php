<?php

namespace App\Models;

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A captured job (§3) — site-scoped. The table is `job_captures`, NOT `jobs` (the database queue driver
 * owns `jobs`). Privacy is schema-level: `client_name_full`, `address_true`, and the true coordinates are
 * internal only and NEVER pushed to WordPress — only `client_name_display` (First + Last initial), the
 * resolved city/county, and the JITTERED coordinates (computed once at capture, stored) reach the public
 * post. The three description fields are distinct on purpose (§7): `raw_description` is the tech's immutable
 * input, `source_description` is the operator-editable seed for every AI call, and `enhanced_description`
 * is the model output (overwritten each run).
 *
 * @property string $id
 * @property string $site_id
 * @property JobSource $source
 * @property JobStatus $status
 * @property Carbon|null $performed_at when the work was done (operator backfill); null for capture-time jobs
 * @property string|null $tech_id soft reference to the capturing tech (§5)
 * @property string|null $client_name_full internal only — never pushed
 * @property string|null $client_name_display "First L." — pushed
 * @property string|null $address_true internal only — never pushed
 * @property float|null $lat_true
 * @property float|null $lng_true
 * @property float|null $lat_jittered
 * @property float|null $lng_jittered
 * @property string|null $job_city_id
 * @property string|null $job_county_id
 * @property list<array{r2_key: string, hash?: string, alt?: string}>|null $photos
 * @property int $primary_photo_index
 * @property string|null $raw_description immutable tech input
 * @property string|null $source_description operator-editable AI seed
 * @property string|null $enhanced_description AI output (overwritten per run)
 * @property string|null $post_title
 * @property string|null $meta_description
 * @property string|null $joby_job_id
 * @property string|null $joby_job_type_raw
 * @property int|null $wp_post_id
 * @property Carbon|null $indexnow_submitted_at when the live job URL was accepted by IndexNow (drives the "Submitted to Bing" pill)
 * @property string|null $last_publish_error
 * @property string|null $reject_reason
 */
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use BelongsToSite, HasFactory, HasUlids, SoftDeletes;

    /** The database queue driver owns `jobs`; the capture record lives in `job_captures`. */
    protected $table = 'job_captures';

    /** A job carries at most this many types (§3) — enforced here, not at the DB. */
    public const MAX_JOB_TYPES = 3;

    /** A job carries at most this many photos (§5) — the capture slots and the review-screen add both honor it. */
    public const MAX_PHOTOS = 3;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => JobSource::class,
            'status' => JobStatus::class,
            'performed_at' => 'date',
            'lat_true' => 'decimal:7',
            'lng_true' => 'decimal:7',
            'lat_jittered' => 'decimal:7',
            'lng_jittered' => 'decimal:7',
            'photos' => 'array',
            'primary_photo_index' => 'integer',
            'wp_post_id' => 'integer',
            'indexnow_submitted_at' => 'datetime',
        ];
    }

    /**
     * The public post title the plugin publishes under — the drafted title, else a "{type} in {city}"
     * fallback. THE single source for the title so the publish blob, the public slug, and the URL never
     * drift apart ({@see JobMetaBlobAssembler} + {@see publicUrl()} both read this).
     */
    public function publicTitle(): string
    {
        $title = trim((string) $this->post_title);
        if ($title !== '') {
            return $title;
        }

        $type = $this->jobTypes->first()?->label;
        $city = $this->job_city_id !== null ? $this->city->name : null;

        return trim(($type ?: 'Completed Job').($city !== null && $city !== '' ? " in {$city}" : ''));
    }

    /** The `pig_job` post slug the plugin publishes under ({title}-{last 6 of the ULID}). */
    public function publicSlug(): string
    {
        return Str::slug($this->publicTitle().'-'.substr($this->id, -6));
    }

    /**
     * The job's live public URL on WordPress — `{domain}/jobs/{slug}/`. The `jobs` base is the companion
     * plugin's default `pig_job` rewrite (`class-job-cpt.php`). Null when the site has no domain.
     */
    public function publicUrl(?string $domain): ?string
    {
        $domain = trim((string) $domain);

        return $domain === '' ? null : rtrim($domain, '/').'/jobs/'.$this->publicSlug().'/';
    }

    /** @return BelongsTo<JobCity, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(JobCity::class, 'job_city_id');
    }

    /** @return BelongsTo<JobCounty, $this> */
    public function county(): BelongsTo
    {
        return $this->belongsTo(JobCounty::class, 'job_county_id');
    }

    /** The applied job types — snapshot rows keyed on `job_capture_id` (max {@see self::MAX_JOB_TYPES}).
     *
     * @return HasMany<JobTypeAssignment, $this>
     */
    public function jobTypes(): HasMany
    {
        return $this->hasMany(JobTypeAssignment::class, 'job_capture_id');
    }

    /**
     * Whether the enhancement pass (§7) produced a usable write-up. The single drafted-vs-undrafted test —
     * approve and (later) the WordPress publish are both gated on it, so an un-enhanced job can never push
     * an empty post.
     */
    public function hasDraft(): bool
    {
        return trim((string) $this->enhanced_description) !== '';
    }
}
