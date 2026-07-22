<?php

namespace App\Filament\Pages\Operate;

use App\Enums\LinkFindingType;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Publishing\Links\InternalLinkAuditor;
use App\Publishing\Links\InternalLinkFixer;
use App\Publishing\Links\LinkFinding;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Operate · Internal Links — the audit + fix surface. Scans the working tenant's PUBLISHED pages with
 * {@see InternalLinkAuditor} and lists every internal-link gap: pages with no inbound link, pages that
 * link nowhere, and copy that names another page's ranking term without linking it (the cross-silo
 * opportunities the composer never makes). Each finding has a one-click, operator-approved "Fix" that
 * edits the page and re-publishes it ({@see InternalLinkFixer}) — nothing is auto-applied.
 *
 * @property-read array<string, mixed> $summary
 */
class InternalLinks extends OperatePage
{
    protected static ?string $slug = 'operate/internal-links';

    protected static ?string $navigationLabel = 'Internal Links';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.operate.internal-links';

    public ?string $siteFilter = null;

    public function mount(): void
    {
        $this->siteFilter = app(ActiveTenant::class)->id();
    }

    public function getSite(): ?Site
    {
        return $this->siteFilter !== null ? Site::query()->find($this->siteFilter) : null;
    }

    /**
     * Findings grouped by type for the view, each row carrying the coordinates a fix needs.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getFindingsProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return [];
        }

        $grouped = [];
        foreach (app(InternalLinkAuditor::class)->audit($site) as $f) {
            $grouped[$f->type->value][] = [
                'type' => $f->type->value,
                'content_id' => $f->contentId,
                'suggested_id' => $f->suggestedContentId,
                'url' => $f->url,
                'title' => $f->title,
                'detail' => $f->detail,
                'suggested_label' => $f->suggestedLabel,
                'fixable' => $f->type === LinkFindingType::Opportunity || $f->suggestedContentId !== null,
            ];
        }

        return $grouped;
    }

    /** Apply one approved finding and re-publish the page it edits. */
    public function fix(string $type, string $contentId, ?string $suggestedId = null): void
    {
        $site = $this->getSite();
        $findingType = LinkFindingType::tryFrom($type);
        if ($site === null || $findingType === null) {
            return;
        }

        $result = app(InternalLinkFixer::class)->fix(
            $site,
            new LinkFinding($findingType, $contentId, '', '', '', $suggestedId),
            Auth::id(),
        );

        $notification = Notification::make()->title($result->applied ? 'Fixed' : 'Nothing to change')->body($result->message);
        ($result->applied ? $notification->success() : $notification->warning())->send();
    }
}
