<?php

namespace App\Filament\Client\Pages;

use App\Client\ClientAccess;
use App\Client\ClientContext;
use App\Client\LinkOpportunities as LinkOpportunitiesView;
use App\Enums\ClientDecision;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The client's Link Opportunities page (§ Links, PR C): the local organisations the engine found that
 * could link to their site, each with what saying yes means, and one decision per row — Interested or
 * Pass. The client decides who gets written to; the operator sends one reviewed email at a time to the
 * Interested ones, within the monthly cap. Thin over {@see LinkOpportunitiesView}.
 *
 * @property-read array<string, mixed> $board
 * @property-read array<string, string> $siteOptions
 */
class LinkOpportunities extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Link Opportunities';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.client.pages.link-opportunities';

    public ?string $siteId = null;

    public string $show = 'review';

    public function mount(): void
    {
        $this->siteId = app(ClientContext::class)->site()?->id;
    }

    public function updatedSiteId(): void
    {
        session(['client_site_id' => $this->siteId]);
    }

    public function getTitle(): string
    {
        return 'Link Opportunities';
    }

    public function setShow(string $show): void
    {
        $this->show = in_array($show, ['review', 'interested', 'passed'], true) ? $show : 'review';
    }

    /** @return array<string, mixed> */
    public function getBoardProperty(): array
    {
        $site = $this->site();

        return $site !== null ? app(LinkOpportunitiesView::class)->for($site) : [
            'to_review' => [], 'interested' => [], 'passed' => [],
            'totals' => ['to_review' => 0, 'interested' => 0, 'passed' => 0, 'contacted' => 0, 'won' => 0],
            'outreach' => ['used' => 0, 'cap' => 0],
        ];
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        $user = app(ClientContext::class)->user();

        return $user === null ? [] : app(ClientAccess::class)->sites($user)->pluck('brand_name', 'id')->all();
    }

    public function interested(string $prospectId): void
    {
        $this->decide($prospectId, ClientDecision::Interested, 'Marked interested — we will reach out and keep you posted here.');
    }

    public function pass(string $prospectId): void
    {
        $this->decide($prospectId, ClientDecision::Pass, 'Passed — we will not contact them.');
    }

    public function undo(string $prospectId): void
    {
        $this->decide($prospectId, null, 'Back in your review list.');
    }

    private function decide(string $prospectId, ?ClientDecision $decision, string $message): void
    {
        $user = app(ClientContext::class)->user();
        $site = $this->site();
        if ($user === null || $site === null || ! app(LinkOpportunitiesView::class)->decide($site, $user, $prospectId, $decision)) {
            Notification::make()->warning()->title('That opportunity is no longer open.')->send();

            return;
        }
        Notification::make()->success()->title($message)->send();
    }

    private function site(): ?Site
    {
        $user = app(ClientContext::class)->user();

        return $user !== null ? app(ClientAccess::class)->currentSite($user, $this->siteId) : null;
    }
}
