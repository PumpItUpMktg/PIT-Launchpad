<?php

namespace App\Pages;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\VoiceProfile;
use App\Standard\StandardPageIntake;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The single place a page's lifecycle is read into the canonical {@see PageState} and rendered for an
 * {@see Audience}. Every surface goes through here — no state strings scattered per-component — so the
 * operator screen now and the client screen later cannot drift.
 *
 * Resolution order mirrors the engine's own gates: in-flight → drafted-state → (no draft) the real
 * blocker (composer / grounding) → a retryable failure → ready. The operator tail is built from the
 * row's own fields; it is diagnostic only and never reaches the client.
 */
class PageStatePresenter
{
    /** @var array<string, int|null> site_id => active voice version (per-request memo) */
    private array $activeVoice = [];

    public function __construct(private readonly GroundingReadiness $grounding = new GroundingReadiness) {}

    public function present(Content $content, Audience $audience): PagePresentation
    {
        $state = $this->resolve($content);

        return new PagePresentation(
            state: $state,
            clientLine: $state->clientLine(),
            whoseMove: $state->whoseMove($audience),
            tone: $state->tone(),
            operatorTail: $audience === Audience::Operator ? $this->tail($content, $state) : null,
        );
    }

    /** Read a page row into its canonical state. */
    public function resolve(Content $content): PageState
    {
        if ($content->isGenerating()) {
            return PageState::Writing;
        }

        if ($content->hasDraft()) {
            return match ($content->status) {
                ContentStatus::Published => PageState::Live,
                ContentStatus::Approved => PageState::Approved,
                ContentStatus::Rendering, ContentStatus::Publishing => PageState::Publishing,
                ContentStatus::RenderFailed, ContentStatus::PublishFailed => PageState::Failed,
                default => PageState::ReadyToReview,
            };
        }

        // No draft yet — the real blocker wins over a stale failure, which wins over "ready".
        if ($content->wireframe_kit_id === null) {
            return PageState::HeldComposer;
        }

        // Missing required brand-narrative intake is its own hold, distinct from missing entities —
        // checked before the grounding gate so the operator sees "needs intake", not a vague pending.
        if (StandardPageIntake::missingRequired($content) !== []) {
            return PageState::HeldIntake;
        }

        if (! $this->grounding->ready($content)) {
            return PageState::HeldGrounding;
        }

        if ($content->draftError() !== null) {
            return PageState::Failed;
        }

        return PageState::ReadyToGenerate;
    }

    /** The append-only operator diagnostic, after the sacred line. Null when there's nothing useful. */
    private function tail(Content $content, PageState $state): ?string
    {
        $base = match ($state) {
            PageState::Writing => $this->queuedTail($content),
            PageState::ReadyToReview => $this->relative('drafted', $content->updated_at),
            PageState::Approved => $this->relative('approved', $content->updated_at),
            PageState::Publishing => 'publishing — pushing to WordPress',
            PageState::Live => $this->liveTail($content),
            PageState::HeldComposer => 'composer pending',
            PageState::HeldGrounding => 'grounding pending — Territory→§1 Market',
            PageState::HeldIntake => $this->intakeTail($content),
            PageState::Failed => $this->failedTail($content),
            PageState::ReadyToGenerate => null,
        };

        // Voice provenance / staleness rides on drafted states: which voice version produced the page,
        // and whether a newer voice is now active (→ regenerate). Drafted-only; held/awaiting have none.
        $voice = $this->voiceNote($content, $state);
        if ($voice !== null) {
            $base = $base !== null && $base !== '' ? "{$base} · {$voice}" : $voice;
        }

        return $base;
    }

    /**
     * The voice-version note for a drafted page: "voice vN", and "(current vM — regenerate)" when a
     * newer voice profile has since become active. Null for non-drafted states or an unversioned page.
     */
    private function voiceNote(Content $content, PageState $state): ?string
    {
        $drafted = $content->voice_profile_version;
        if ($drafted === null || $drafted === 0) {
            return null;
        }

        if (! in_array($state, [PageState::ReadyToReview, PageState::Approved, PageState::Publishing, PageState::Live], true)) {
            return null;
        }

        $active = $this->activeVoiceVersion((string) $content->site_id);

        return $active !== null && $active > $drafted
            ? "voice v{$drafted} (current v{$active} — regenerate)"
            : "voice v{$drafted}";
    }

    private function activeVoiceVersion(string $siteId): ?int
    {
        if (array_key_exists($siteId, $this->activeVoice)) {
            return $this->activeVoice[$siteId];
        }

        $version = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->max('version');

        return $this->activeVoice[$siteId] = $version !== null ? (int) $version : null;
    }

    private function queuedTail(Content $content): string
    {
        $at = $content->meta['generating_at'] ?? null;

        return is_string($at) ? 'queued '.Carbon::parse($at)->diffForHumans() : 'queued';
    }

    private function liveTail(Content $content): string
    {
        $parts = [];
        if ($content->wp_post_id !== null) {
            $parts[] = 'post #'.$content->wp_post_id;
        }
        $slug = (string) $content->slug;
        if ($slug !== '') {
            $parts[] = '/'.ltrim($slug, '/');
        }
        if ($content->published_at !== null) {
            $parts[] = 'published '.$content->published_at->diffForHumans();
        }

        return $parts === [] ? 'live' : implode(' · ', $parts);
    }

    private function intakeTail(Content $content): string
    {
        $missing = StandardPageIntake::missingRequired($content);

        return $missing === [] ? 'needs intake' : 'needs intake — '.implode(', ', $missing);
    }

    private function failedTail(Content $content): string
    {
        // Generate failure records draft_error; a publish failure records last_publish_error. Coalesce
        // to '' so a render-failure with no publish error string is still null-safe at runtime.
        $error = trim((string) ($content->draftError() ?? $content->last_publish_error));

        return $error !== '' ? $error : 'generation or publish failed';
    }

    private function relative(string $verb, ?CarbonInterface $at): ?string
    {
        return $at !== null ? $verb.' '.$at->diffForHumans() : null;
    }
}
