<?php

namespace App\Filament\Pages;

use App\Enums\ClientDecision;
use App\Enums\LinkProspectKind;
use App\Enums\LinkProspectStatus;
use App\Jobs\FindLinkProspects;
use App\Jobs\FindLocalOpportunities;
use App\Jobs\FindProspectContact;
use App\Links\OfficeAttribution;
use App\Links\Outreach\FollowUpComposer;
use App\Links\Outreach\GuardResult;
use App\Links\Outreach\OutreachGuard;
use App\Links\Outreach\OutreachRefused;
use App\Links\Outreach\OutreachSender;
use App\Links\Outreach\Sender;
use App\Links\OutreachKitDrafter;
use App\Models\LinkProspect;
use App\Models\LinkProspectRun;
use App\Models\Location;
use App\Models\OutreachEmail;
use App\Models\OutreachKit;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Link prospects (§ Links) — the domains that link to the working tenant's local competitors and not to the
 * tenant, found by the competitor intersect, ranked by how many competitors each links to. The operator
 * works the list by hand: open the site, set a status, keep a note. "Find prospects" queues one intersect
 * on the worker. Nothing here contacts anyone or submits anything.
 *
 * @property-read list<LinkProspect> $prospects
 * @property-read list<array{id: string, name: string}> $offices
 * @property-read LinkProspectRun|null $lastRun
 * @property-read array{prospect: LinkProspect, kit: ?OutreachKit}|null $activeKit
 * @property-read array{count: int, last_found_at: Carbon|null, by_status: array<string, int>} $summary
 */
class LinkProspectsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Link prospects';

    protected static ?string $title = 'Link prospects';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'link-prospects';

    protected string $view = 'filament.pages.link-prospects';

    public ?string $siteId = null;

    #[Url]
    public string $status = 'open';

    /** 'all', 'competitors' (the backlink intersect), or one LinkProspectKind value. */
    #[Url]
    public string $kind = 'all';

    /** The prospect whose kit is open in the side panel. */
    public ?string $activeProspectId = null;

    /** The office the open kit speaks for ('' = the brand, linking to the home page). */
    public string $kitLocationId = '';

    /** The email as it will go — pre-filled from the kit (or the follow-up composer), edited by the person, sent verbatim. */
    public string $sendSubject = '';

    public string $sendBody = '';

    /** pitch | follow_up — which email the send box holds. */
    public string $sendKind = OutreachEmail::PITCH;

    public string $contactEmailInput = '';

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canOperate() ?? false;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** @return list<LinkProspect> */
    public function getProspectsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }
        $q = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId);
        match ($this->status) {
            'open' => $q->whereIn('status', LinkProspectStatus::openValues()),
            'interested' => $q->where('client_decision', ClientDecision::Interested->value)->whereIn('status', LinkProspectStatus::openValues()),
            'all' => null,
            default => $q->where('status', $this->status),
        };
        $lanes = array_map(fn (LinkProspectKind $k): string => $k->value, LinkProspectKind::lanes());
        match ($this->kind) {
            'all' => null,
            'competitors' => $q->whereNotIn('kind', $lanes),
            default => $q->where('kind', $this->kind),
        };

        return $q->orderByDesc('competitor_count')->orderByDesc('locality')->orderByDesc('rank')->orderBy('domain')->get()->all();
    }

    /**
     * The rows grouped by office: each office's local opportunities under it, state-wide ones under the
     * corporate location only, competitor-link rows last.
     *
     * @return list<array{key: string, label: string, rows: list<LinkProspect>}>
     */
    public function getGroupsProperty(): array
    {
        $site = $this->site();
        $rows = $this->prospects;
        if ($site === null || $rows === []) {
            return [];
        }
        $attribution = app(OfficeAttribution::class)->for($site, collect($rows));
        $groups = [];
        foreach ($rows as $p) {
            $a = $attribution[(string) $p->id];
            $groups[$a['key']] ??= ['key' => $a['key'], 'label' => $a['label'], 'order' => $a['order'], 'rows' => []];
            $groups[$a['key']]['rows'][] = $p;
        }
        usort($groups, fn (array $x, array $y): int => $x['order'] <=> $y['order']);

        return array_map(fn (array $g): array => ['key' => $g['key'], 'label' => $g['label'], 'rows' => $g['rows']], $groups);
    }

    /** @return array{count: int, last_found_at: Carbon|null, by_status: array<string, int>} */
    public function getSummaryProperty(): array
    {
        if ($this->siteId === null) {
            return ['count' => 0, 'last_found_at' => null, 'by_status' => []];
        }
        $all = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->get(['status', 'found_at']);

        return [
            'count' => $all->count(),
            'last_found_at' => $all->max('found_at'),
            'by_status' => $all->countBy(fn (LinkProspect $p): string => $p->status->value)->all(),
        ];
    }

    /** The latest intersect for the working tenant: who was asked about, who was excluded, what was kept. */
    public function getLastRunProperty(): ?LinkProspectRun
    {
        return $this->siteId === null ? null
            : LinkProspectRun::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->orderByDesc('ran_at')->first();
    }

    /** Queue one competitor intersect for the working tenant. */
    public function findProspects(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }
        FindLinkProspects::dispatch((string) $site->id);
        Notification::make()->success()->title('Finding link prospects')
            ->body('One backlink-index call on the worker: the domains linking to the local competitors Town Rank finds above you, and not to you. Reload in a minute.')
            ->send();
    }

    /** Queue one local-opportunity search (sponsorships, memberships, dealer locators, job boards) for the working tenant. */
    public function findLocalOpportunities(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }
        FindLocalOpportunities::dispatch((string) $site->id);
        Notification::make()->success()->title('Finding local opportunities')
            ->body('Searching each office\'s town and county for sponsorship, membership, dealer-locator and job-board pages on the worker. Reload in a few minutes.')
            ->send();
    }

    public function setStatus(string $prospectId, string $status): void
    {
        $value = LinkProspectStatus::tryFrom($status);
        $prospect = $this->siteId === null || $value === null ? null
            : LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($prospectId);
        if ($prospect === null) {
            return;
        }
        $prospect->forceFill(['status' => $value])->save();
    }

    public function setNotes(string $prospectId, string $notes): void
    {
        $prospect = $this->siteId === null ? null
            : LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($prospectId);
        if ($prospect === null) {
            return;
        }
        $prospect->forceFill(['notes' => trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null])->save();
    }

    public function openKit(string $prospectId): void
    {
        $this->activeProspectId = $prospectId;
        $prospect = $this->activeProspect();
        $this->contactEmailInput = (string) $prospect?->contact_email;
        // A state-level body is approached once, by the company; a local one defaults to the office that found it.
        $this->kitLocationId = $prospect !== null && ! self::isStateLevel($prospect) ? (string) ($prospect->location_id ?? '') : '';
        $this->prefillSend();
    }

    /** True for the open prospect when it is a state-level organisation (found by a state search, not an office's). */
    public function getStateLevelProperty(): bool
    {
        $prospect = $this->activeProspect();

        return $prospect !== null && self::isStateLevel($prospect);
    }

    /** A state-level body: an opportunity found by a state search — no office behind it. One tenant, one approach. */
    public static function isStateLevel(LinkProspect $prospect): bool
    {
        return $prospect->kind->isLane() && $prospect->location_id === null;
    }

    public function closeKit(): void
    {
        $this->activeProspectId = null;
        $this->sendSubject = $this->sendBody = '';
        $this->sendKind = OutreachEmail::PITCH;
    }

    public function updatedKitLocationId(): void
    {
        $prospect = $this->activeProspect();
        if ($prospect !== null && self::isStateLevel($prospect)) {
            $this->kitLocationId = '';   // the picker is locked for a state-level body: the company speaks, once
        }
        $this->prefillSend();
    }

    /** The operator records the client's call here (PR C lets the client do it in the portal). */
    public function setDecision(string $prospectId, string $decision): void
    {
        $prospect = $this->siteId === null ? null
            : LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($prospectId);
        if ($prospect === null) {
            return;
        }
        $value = ClientDecision::tryFrom($decision);
        $prospect->forceFill(['client_decision' => $value, 'client_decided_at' => $value !== null ? now() : null, 'client_decided_by' => $value !== null ? Auth::id() : null])->save();
    }

    public function setContact(): void
    {
        $prospect = $this->activeProspect();
        if ($prospect === null) {
            return;
        }
        $email = strtolower(trim($this->contactEmailInput));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Notification::make()->danger()->title('That is not an email address')->send();

            return;
        }
        $prospect->forceFill(['contact_email' => $email !== '' ? $email : null, 'contact_source' => $email !== '' ? 'manual' : null])->save();
        Notification::make()->success()->title($email !== '' ? "Contact set: {$email}" : 'Contact cleared')->send();
    }

    public function pickContact(string $email): void
    {
        $this->contactEmailInput = $email;
        $this->setContact();
    }

    /** Queue one read of the prospect's page for a contact address. */
    public function findContact(): void
    {
        $prospect = $this->activeProspect();
        if ($prospect === null) {
            return;
        }
        FindProspectContact::dispatch((string) $prospect->id);
        Notification::make()->success()->title('Looking for a contact')->body('Reading the page (and its contact page) on the worker. Reopen the panel in a minute.')->send();
    }

    /** The guard's checklist for the email in the send box — red until every line is green. */
    public function getSendGuardProperty(): ?GuardResult
    {
        $prospect = $this->activeProspect();
        $site = $this->site();

        return $prospect === null || $site === null ? null : app(OutreachGuard::class)->check($site, $prospect, $this->sendKind);
    }

    /** @return array{email: string, name: string}|null */
    public function getSenderProperty(): ?array
    {
        $site = $this->site();

        return $site === null ? null : Sender::for($site);
    }

    /** @return array{used: int, cap: int} */
    public function getMonthUsageProperty(): array
    {
        $site = $this->site();
        if ($site === null) {
            return ['used' => 0, 'cap' => 0];
        }

        return ['used' => app(OutreachGuard::class)->usedThisMonth($site), 'cap' => OutreachGuard::capFor($site)];
    }

    /** @return list<OutreachEmail> */
    public function getSentEmailsProperty(): array
    {
        $prospect = $this->activeProspect();

        return $prospect === null ? []
            : OutreachEmail::withoutGlobalScope(SiteScope::class)->where('link_prospect_id', $prospect->id)->orderBy('approved_at')->get()->all();
    }

    /** One click, one email: the guard runs again inside the sender's lock; refusals come back as the reasons. */
    public function approveAndSend(): void
    {
        $prospect = $this->activeProspect();
        $site = $this->site();
        if ($prospect === null || $site === null) {
            return;
        }
        $kit = $this->getActiveKitProperty()['kit'] ?? null;
        try {
            $email = app(OutreachSender::class)->approve($site, $prospect, $this->sendKind, $this->sendSubject, $this->sendBody, $kit, Auth::user());
        } catch (OutreachRefused $e) {
            Notification::make()->danger()->title('Not sent')->body(implode(' ', $e->reasons))->persistent()->send();

            return;
        }
        Notification::make()->success()->title($this->sendKind === OutreachEmail::FOLLOW_UP ? "Follow-up approved for {$prospect->domain}" : "Pitch approved for {$prospect->domain}")
            ->body("Sending to {$email->to_email} from {$email->from_email} on the worker.")->send();
        $this->prefillSend();
    }

    /** Pre-fill the send box: the pitch from the kit while no pitch has gone; the follow-up once one has. */
    private function prefillSend(): void
    {
        $prospect = $this->activeProspect();
        $site = $this->site();
        if ($prospect === null || $site === null) {
            return;
        }
        $pitch = OutreachEmail::withoutGlobalScope(SiteScope::class)->where('link_prospect_id', $prospect->id)
            ->where('kind', OutreachEmail::PITCH)->where('status', '!=', OutreachEmail::FAILED)->orderByDesc('approved_at')->first();
        if ($pitch !== null) {
            $this->sendKind = OutreachEmail::FOLLOW_UP;
            $draft = app(FollowUpComposer::class)->compose($site, $pitch);
            $this->sendSubject = $draft['subject'];
            $this->sendBody = $draft['body'];

            return;
        }
        $this->sendKind = OutreachEmail::PITCH;
        $kit = $this->getActiveKitProperty()['kit'] ?? null;
        $this->sendSubject = (string) ($kit->payload['pitch_subject'] ?? '');
        $this->sendBody = (string) ($kit->payload['pitch_body'] ?? '');
    }

    /** @return list<array{id: string, name: string}> */
    public function getOfficesProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Location $l): array => ['id' => (string) $l->id, 'name' => (string) $l->name])->all();
    }

    /** @return array{prospect: LinkProspect, kit: ?OutreachKit}|null */
    public function getActiveKitProperty(): ?array
    {
        $prospect = $this->activeProspect();
        if ($prospect === null) {
            return null;
        }
        $kit = OutreachKit::withoutGlobalScope(SiteScope::class)->where('site_id', $prospect->site_id)
            ->where('location_id', $this->kitLocationId !== '' ? $this->kitLocationId : null)->where('target_domain', $prospect->domain)->first();

        return ['prospect' => $prospect, 'kit' => $kit];
    }

    /** Draft (or redraft) the pitch for the open prospect, speaking for the chosen office — one cheap model call, kept. */
    public function draftKit(bool $redraft = false): void
    {
        $prospect = $this->activeProspect();
        if ($prospect === null) {
            return;
        }
        $location = $this->kitLocationId !== ''
            ? Location::withoutGlobalScope(SiteScope::class)->where('site_id', $prospect->site_id)->find($this->kitLocationId)
            : null;
        $kit = app(OutreachKitDrafter::class)->forProspect($prospect, $location, $redraft);
        Notification::make()->{$kit->model_ok ? 'success' : 'warning'}()
            ->title($kit->model_ok ? "Pitch ready for {$prospect->domain}" : "Pitch for {$prospect->domain} uses the plain fallback copy")
            ->send();
        $this->prefillSend();
    }

    private function activeProspect(): ?LinkProspect
    {
        return $this->siteId === null || $this->activeProspectId === null ? null
            : LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($this->activeProspectId);
    }

    private function site(): ?Site
    {
        return $this->siteId !== null ? Site::withoutGlobalScopes()->find($this->siteId) : null;
    }
}
