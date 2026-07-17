<?php

namespace App\Filament\Concerns;

use App\Branding\BrandVariationBuilder;
use App\Branding\LogoIntake;
use App\Guided\StepGate;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Onboarding\MissionPolisher;
use App\Publishing\TenantStorage;
use App\Styling\StyleActivator;
use App\Styling\StyleVariation;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The brand kit surface — LOOK (style-variation pick + the WP push) and NARRATIVE (story /
 * mission with opt-in polish / values / differentiators / team) — extracted from the guided
 * Brand step so the new Setup's Brand step hosts the identical proven behavior. Voice stays
 * out (the new Setup has its own Interview + Voice steps).
 *
 * Host contract: `getSite(): ?Site`, `WithFileUploads` (the team photo), and
 * {@see brandPushBlocked()} — the pre-push gate message (deps_ready on the guided page,
 * a WP-connection check on the new step).
 */
trait ManagesBrandKit
{
    // Brand-narrative intake (the words the standard-page composer grounds About / Why-Choose-Us on).
    public string $story = '';

    public string $mission = '';

    /**
     * Opt-in AI cleanup of the mission wording (verbatim by default). When checked, a save polishes
     * the typed mission through {@see MissionPolisher} — grammar/tightening only, never new claims —
     * writes the result back into the field so the client sees exactly what will render, and keeps
     * their original wording in SiteNarrative.mission_raw.
     */
    public bool $missionEnhance = false;

    /** One value per line. */
    public string $valuesText = '';

    /** One differentiator per line. */
    public string $differentiatorsText = '';

    /**
     * The real team — each {name, role, bio, photo_url}. Drives the About team grid (real faces are
     * the strongest trust content on the site; a member with no photo renders an initials chip, never
     * a fabricated headshot). Persisted immediately on add/remove.
     *
     * @var list<array{name: string, role: string, bio: string, photo_url: string}>
     */
    public array $team = [];

    public string $newTeamName = '';

    public string $newTeamRole = '';

    public string $newTeamBio = '';

    /** The pending member photo upload (optional — real photos highly recommended). */
    public mixed $teamPhoto = null;

    /** The pending logo upload (optional) — processed the moment it's dropped in. */
    public mixed $logoUpload = null;

    /**
     * The stored logo for display: url + extracted primary/accent — null until one is stored.
     * The extracted palette feeds the "Your brand colors" style option.
     *
     * @var array{url: string, primary: ?string, accent: ?string}|null
     */
    public ?array $logoInfo = null;

    /** A warning message when the brand push is not yet possible, or null when it may proceed. */
    abstract protected function brandPushBlocked(): ?string;

    /** Load the stored narrative + logo into the form (mount / site-switch). */
    protected function loadBrandState(Site $site): void
    {
        $this->logoInfo = $this->existingLogo($site);

        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($narrative === null) {
            return;
        }

        $this->story = (string) ($narrative->story ?? '');
        $this->mission = (string) ($narrative->mission ?? '');
        // A stored raw mission means the client opted into AI enhancement last time.
        $this->missionEnhance = ($narrative->mission_raw ?? null) !== null;
        $this->valuesText = $this->toLines($narrative->values);
        $this->differentiatorsText = $this->toLines($narrative->differentiators);
        $this->team = $this->teamRows($narrative->team);
    }

    /**
     * Optional logo — processed the MOMENT it's uploaded (stored to R2, brand colors extracted,
     * persisted to SiteBranding.logo_set), so the "Your brand colors" style option can appear.
     * Never blocks the step. Livewire fires this hook when the `logoUpload` property changes.
     */
    public function updatedLogoUpload(): void
    {
        $site = $this->getSite();
        if ($site === null || ! $this->logoUpload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate([
            'logoUpload' => ['file', 'mimetypes:image/png,image/jpeg,image/svg+xml,text/plain', 'max:4096'],
        ], [], ['logoUpload' => 'logo']);

        $ext = strtolower($this->logoUpload->getClientOriginalExtension() ?: (string) $this->logoUpload->guessExtension());
        $set = app(LogoIntake::class)->store($site, (string) $this->logoUpload->get(), $ext);

        $this->logoUpload = null;
        $this->logoInfo = $this->displayLogo($set);

        Notification::make()->title('Logo saved.')
            ->body(isset($set['primary']) ? 'Your brand colors are ready as a style option below.' : 'Added to your site header.')
            ->success()->send();
    }

    public function removeLogo(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($branding !== null) {
            $set = is_array($branding->logo_set) ? $branding->logo_set : [];
            unset($set['url'], $set['r2_key'], $set['ext'], $set['primary'], $set['accent']);
            $branding->update(['logo_set' => $set]);
        }
        // Drop the logo-colors style choice too — its source is gone.
        $site->update(['use_logo_colors' => false]);
        $this->logoInfo = null;
    }

    private function existingLogo(Site $site): ?array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

        return $this->displayLogo(is_array($branding?->logo_set) ? $branding->logo_set : []);
    }

    /**
     * @param  array<string, mixed>  $set
     * @return array{url: string, primary: ?string, accent: ?string}|null
     */
    private function displayLogo(array $set): ?array
    {
        $url = trim((string) ($set['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        return [
            'url' => $url,
            'primary' => isset($set['primary']) ? (string) $set['primary'] : null,
            'accent' => isset($set['accent']) ? (string) $set['accent'] : null,
        ];
    }

    public function getPushedProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(StepGate::class)->state($site)->brand_pushed;
    }

    /** The style variation this site will render in (operator override → voice recommendation → default). */
    public function getResolvedStyleProperty(): ?StyleVariation
    {
        $site = $this->getSite();

        return $site !== null ? app(StyleActivator::class)->resolve($site) : null;
    }

    /** The operator's explicit override, or null when following the recommendation. */
    public function getChosenStyleProperty(): ?StyleVariation
    {
        return $this->getSite()?->style_variation;
    }

    /**
     * The logo-derived palette for the "Your brand colors" option — {primary, accent} for the swatch,
     * or null when no usable logo palette exists (the option is then data-gated OUT of the picker).
     *
     * @return array{primary: string, accent: string}|null
     */
    public function getLogoColorsProperty(): ?array
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        $colors = app(StyleActivator::class)->logoColors($site);
        if ($colors === null) {
            return null;
        }

        // Resolve through the builder so the swatch shows the ACTUAL accent (borrowed for a monochrome logo).
        $resolved = app(BrandVariationBuilder::class)->resolve($colors);

        return ['primary' => $resolved['primary'], 'accent' => $resolved['accent']];
    }

    /** Whether "Your brand colors" is the current choice. */
    public function getUsesLogoColorsProperty(): bool
    {
        return (bool) $this->getSite()?->use_logo_colors;
    }

    /**
     * Operator override of the recommended style. `auto` clears the override (follow the voice
     * recommendation). The Gutenberg pivot's recommend-with-override: the system suggests, the human
     * confirms/overrides — brand styling is one of the three theme.json variations.
     */
    public function chooseStyle(string $variation): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        // "Your brand colors" — the logo-derived variation. Kept out of StyleVariation (a separate flag)
        // so the recommendation stays voice-driven; this is an override.
        if ($variation === 'brand_colors') {
            $site->forceFill(['use_logo_colors' => true])->save();
            Notification::make()->title('Style set to your brand colors.')->success()->send();

            return;
        }

        $picked = $variation === 'auto' ? null : StyleVariation::tryFrom($variation);
        $site->forceFill(['style_variation' => $picked, 'use_logo_colors' => false])->save();

        Notification::make()
            ->title($picked !== null ? "Style set to {$picked->label()}." : 'Using the recommended style.')
            ->success()->send();
    }

    /**
     * Apply the resolved style variation to WordPress — the pivot's brand push. Activates a
     * theme.json style variation (bold/clean/warm, or the logo-derived brand colors) as the site's
     * global styles; there is no Elementor Global Kit. `brand_pushed` is the completion flag.
     */
    public function pushBrand(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $blocked = $this->brandPushBlocked();
        if ($blocked !== null) {
            Notification::make()->title($blocked)->warning()->send();

            return;
        }

        $result = app(StyleActivator::class)->activate($site);
        $variationValue = (string) ($result['variation'] ?? '');
        $label = $variationValue === BrandVariationBuilder::SLUG
            ? BrandVariationBuilder::TITLE
            : (StyleVariation::tryFrom($variationValue)?->label() ?? 'your style');

        if ($result['updated'] ?? false) {
            app(StepGate::class)->state($site)->update(['brand_pushed' => true]);
            Notification::make()->title("Applied {$label} to your site.")->success()->send();

            return;
        }

        Notification::make()->title('Could not apply your style')->body((string) ($result['error'] ?? 'Try again.'))->danger()->send();
    }

    /**
     * Save the brand-narrative intake the standard-page composer grounds on. Optional by design —
     * a page whose required intake is absent holds "needs intake" rather than fabricating; this is
     * the step that supplies it so About / Why Choose Us can generate.
     */
    public function saveNarrative(): void
    {
        if ($this->getSite() === null) {
            return;
        }

        $polish = $this->persistNarrative();

        match ($polish) {
            'polished' => Notification::make()->title('Brand details saved — mission polished.')
                ->body('The polished wording is in the mission field above; edit and save again to adjust it.')
                ->success()->send(),
            'fallback' => Notification::make()->title('Brand details saved.')
                ->body('AI enhancement wasn\'t available right now, so your mission was saved exactly as written.')
                ->warning()->send(),
            default => Notification::make()->title('Brand details saved.')->success()->send(),
        };
    }

    /**
     * Persist the narrative, applying the opt-in mission polish. Returns the polish outcome for the
     * caller's notification: 'polished' (AI cleaned it up — the field now shows the result),
     * 'fallback' (enhancement was requested but unavailable — saved verbatim), or null (verbatim by
     * choice / nothing to polish).
     */
    protected function persistNarrative(): ?string
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        [$mission, $missionRaw, $outcome] = $this->resolveMission();

        SiteNarrative::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $site->id],
            [
                'story' => $this->clean($this->story),
                'mission' => $mission,
                'mission_raw' => $missionRaw,
                'values' => $this->fromLines($this->valuesText),
                'differentiators' => $this->fromLines($this->differentiatorsText),
                'team' => $this->team !== [] ? $this->team : null,
            ],
        );

        return $outcome;
    }

    /**
     * Add a team member — name required; the photo (optional, but real faces are the strongest trust
     * content on the site) is stored to the tenant's R2 prefix and its URL kept on the member row.
     * Persists immediately (an explicit Add is durable; nothing is lost before "Save").
     */
    public function addTeamMember(): void
    {
        $site = $this->getSite();
        $name = trim($this->newTeamName);
        if ($site === null || $name === '') {
            return;
        }

        $photoUrl = '';
        if ($this->teamPhoto instanceof TemporaryUploadedFile) {
            $this->validate([
                'teamPhoto' => ['file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:6144'],
            ], [], ['teamPhoto' => 'photo']);

            $ext = strtolower($this->teamPhoto->getClientOriginalExtension() ?: (string) $this->teamPhoto->guessExtension());
            $key = app(TenantStorage::class)->put(
                $site,
                'team-'.Str::slug($name).'-'.Str::lower(Str::random(6)).'.'.$ext,
                (string) $this->teamPhoto->get(),
            );
            $photoUrl = Storage::disk(TenantStorage::DISK)->url($key);
        }

        $this->team[] = [
            'name' => $name,
            'role' => trim($this->newTeamRole),
            'bio' => trim($this->newTeamBio),
            'photo_url' => $photoUrl,
        ];

        $this->newTeamName = '';
        $this->newTeamRole = '';
        $this->newTeamBio = '';
        $this->teamPhoto = null;

        $this->persistNarrative();
    }

    public function removeTeamMember(int $index): void
    {
        unset($this->team[$index]);
        $this->team = array_values($this->team);
        $this->persistNarrative();
    }

    /**
     * Normalize stored team rows (possibly older mixed shapes) into the form's {name, role, bio,
     * photo_url} rows — only members with a name.
     *
     * @return list<array{name: string, role: string, bio: string, photo_url: string}>
     */
    private function teamRows(mixed $stored): array
    {
        $rows = [];
        foreach (is_array($stored) ? $stored : [] as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'role' => trim((string) ($member['role'] ?? $member['title'] ?? '')),
                'bio' => trim((string) ($member['bio'] ?? $member['description'] ?? '')),
                'photo_url' => trim((string) ($member['photo_url'] ?? $member['photo'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * The mission to store: the client's wording verbatim, or — when they opted into enhancement —
     * the AI-polished statement, with their original kept in mission_raw. The polished text is written
     * back into the field so the client always sees exactly what will render on their site. Fail-open:
     * a failed polish stores the verbatim wording (never blocks the save, never loses their words).
     *
     * @return array{0: string|null, 1: string|null, 2: string|null} [mission, mission_raw, outcome]
     */
    private function resolveMission(): array
    {
        $typed = $this->clean($this->mission);

        if (! $this->missionEnhance || $typed === null) {
            return [$typed, null, null];
        }

        $polished = app(MissionPolisher::class)->polish($typed);
        if ($polished === null) {
            return [$typed, null, 'fallback'];
        }
        if ($polished === $typed) {
            return [$typed, null, null]; // already clean — verbatim, nothing to review
        }

        $this->mission = $polished; // the client sees the published wording, editable

        return [$polished, $typed, 'polished'];
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Split a textarea into a trimmed, non-empty list — null when empty (degrade by omission).
     *
     * @return list<string>|null
     */
    private function fromLines(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $l): bool => $l !== ''));

        return $lines === [] ? null : $lines;
    }

    /** Render a stored list (strings or {title, description}) back into one-per-line textarea text. */
    private function toLines(mixed $items): string
    {
        if (! is_array($items)) {
            return '';
        }

        $lines = array_map(function (mixed $item): string {
            if (is_string($item)) {
                return $item;
            }
            if (is_array($item)) {
                $title = trim((string) ($item['title'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));

                return $description !== '' ? "{$title} — {$description}" : $title;
            }

            return '';
        }, $items);

        return implode("\n", array_filter($lines, fn (string $l): bool => $l !== ''));
    }
}
