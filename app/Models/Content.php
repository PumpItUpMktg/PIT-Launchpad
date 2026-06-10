<?php

namespace App\Models;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\PageType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property ContentStatus $status
 * @property ContentKind $kind
 * @property PageType|null $page_type
 * @property DraftTrigger|null $draft_trigger
 * @property bool $locked
 * @property bool $locally_edited
 * @property int|null $wp_post_id
 * @property string|null $near_dup_of_content_id
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $meta
 * @property array<string, mixed>|null $slot_payload
 * @property array<string, mixed>|null $schema_payload
 * @property array<string, mixed>|null $verification
 */
class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use BelongsToSite, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /**
     * Whether a re-publish must NOT overwrite the live page — the operator
     * locked it, or the plugin reported it edited directly in WordPress.
     */
    public function isPublishProtected(): bool
    {
        return $this->locked || $this->locally_edited;
    }

    /**
     * Whether a real draft has been produced. A post needs body HTML; a page
     * needs filled kit slots. A candidate whose status was flipped without a
     * persisted draft (the silent-failure case) is NOT drafted — it must never
     * approve or publish (it would push an empty post to WordPress).
     */
    public function hasDraft(): bool
    {
        if ($this->kind === ContentKind::Page) {
            return is_array($this->slot_payload) && $this->slot_payload !== [];
        }

        return is_string($this->body) && trim($this->body) !== '';
    }

    /** The persisted last-draft failure reason (the silent-failure marker), if any. */
    public function draftError(): ?string
    {
        $error = $this->meta['draft_error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * The generation-state machine for the surfaces (single source of truth):
     * `drafted` (has body/slots) → `failed` (a recorded draft error) →
     * `generating` (a queued GeneratePost job is in flight) → `awaiting`.
     */
    public function generationState(): string
    {
        if ($this->hasDraft()) {
            return 'drafted';
        }

        if ($this->draftError() !== null) {
            return 'failed';
        }

        return ($this->meta['generating_at'] ?? null) !== null ? 'generating' : 'awaiting';
    }

    /** Whether a queued GeneratePost job is in flight for this row. */
    public function isGenerating(): bool
    {
        return $this->generationState() === 'generating';
    }

    /**
     * Stamp the row "generating" (clearing any prior failure marker so a re-run
     * doesn't read as failed while it works). Set before dispatch so the UI
     * reflects it immediately.
     */
    public function markGenerating(): void
    {
        $meta = $this->meta ?? [];
        $meta['generating_at'] = now()->toIso8601String();
        unset($meta['draft_error'], $meta['draft_failure'], $meta['draft_failed_at']);

        $this->forceFill(['meta' => $meta])->save();
    }

    /** Drop the generating marker (on failure; success rebuilds meta wholesale). */
    public function clearGenerating(): void
    {
        $meta = $this->meta ?? [];
        unset($meta['generating_at']);

        $this->forceFill(['meta' => $meta])->save();
    }

    /** @return BelongsTo<Silo, $this> */
    public function silo(): BelongsTo
    {
        return $this->belongsTo(Silo::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<Keyword, $this> */
    public function targetKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'target_keyword_id');
    }

    /** @return BelongsTo<WireframeKit, $this> */
    public function wireframeKit(): BelongsTo
    {
        return $this->belongsTo(WireframeKit::class);
    }

    /**
     * The relevance-time matched silo for a reactive candidate. FK is not
     * DB-enforced (additive ALTER); resolved at the model level.
     *
     * @return BelongsTo<Silo, $this>
     */
    public function matchedSilo(): BelongsTo
    {
        return $this->belongsTo(Silo::class, 'matched_silo_id');
    }

    /**
     * The existing content this draft was flagged a near-duplicate of (§6a).
     * FK is not DB-enforced (additive ALTER; §1 deferred-FK pattern).
     *
     * @return BelongsTo<Content, $this>
     */
    public function nearDupOf(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'near_dup_of_content_id');
    }

    /** @return HasMany<RefreshEvent, $this> */
    public function refreshEvents(): HasMany
    {
        return $this->hasMany(RefreshEvent::class);
    }

    /**
     * The content this draft refreshes (re-draft in place). FK is not
     * DB-enforced (additive ALTER; matches the §1 deferred-FK pattern).
     *
     * @return BelongsTo<Content, $this>
     */
    public function refreshOf(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'refresh_of_content_id');
    }

    /** @return HasMany<ContentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    /** @return HasOne<SerpSnapshot, $this> */
    public function serpSnapshot(): HasOne
    {
        return $this->hasOne(SerpSnapshot::class);
    }

    /** @return HasMany<RenderJob, $this> */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    /** @return BelongsToMany<MediaAsset, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'content_media');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ContentKind::class,
            'page_type' => PageType::class,
            'intake_type' => IntakeType::class,
            'status' => ContentStatus::class,
            'meta' => 'array',
            'schema_payload' => 'array',
            'slot_payload' => 'array',
            'voice_profile_version' => 'integer',
            'wireframe_kit_version' => 'integer',
            'wp_post_id' => 'integer',
            'version' => 'integer',
            'published_at' => 'datetime',
            'relevance_score' => 'decimal:4',
            'local_relevance' => 'boolean',
            'draft_trigger' => DraftTrigger::class,
            'verification' => 'array',
            'last_refreshed_at' => 'datetime',
            'refresh_count' => 'integer',
            'locked' => 'boolean',
            'locally_edited' => 'boolean',
        ];
    }
}
