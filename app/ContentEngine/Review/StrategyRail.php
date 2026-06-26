<?php

namespace App\ContentEngine\Review;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;

/**
 * The proof-step strategy rail (§5) — read-only context beside the preview that turns "does this
 * read well?" into "does this page do its JOB?". Three groups, each answering one question:
 *
 *  - Placement — silo + role: is it where it belongs?
 *  - Target — primary keyword + volume + secondary terms: what's it going after, is it worth it?
 *  - Performance — the Local Falcon slot, dark for now: empty-on-purpose ("tracked after publish"),
 *    never a hidden gap. The rail is built to HOLD this group so wiring Local Falcon later is a
 *    fill-in, not a redesign.
 *
 * Targeting is read-only here (it's set in Structure; changing it reshapes the page's content).
 */
class StrategyRail
{
    public function __construct(private readonly ServiceAlignment $serviceAlignment = new ServiceAlignment) {}

    /**
     * @return array{placement: array<string, mixed>, target: array<string, mixed>, performance: array<string, mixed>}
     */
    public function for(Content $page): array
    {
        return [
            'placement' => $this->placement($page),
            'target' => $this->target($page),
            'performance' => $this->performance(),
            // The read-only note: targeting lives in Structure (where the change actually belongs).
            'locked_note' => 'Targeting is set in Structure. Changing it reshapes this page\'s content.',
        ];
    }

    /**
     * Placement answers "is this page where it belongs, and is it about the right thing?" — silo +
     * role, plus the pinned service subject and a mismatch flag (the catch that turns a wrong-service
     * draft from a silent publish into a visible warning at review).
     *
     * @return array{silo: ?string, role: string, label: string, subject: ?string, mismatch: bool, mismatch_note: ?string}
     */
    private function placement(Content $page): array
    {
        $silo = $page->silo_id !== null
            ? Silo::withoutGlobalScope(SiteScope::class)->find($page->silo_id)
            : null;
        $siloName = $silo instanceof Silo ? (string) $silo->name : null;
        $role = $this->role($page->page_type);

        $alignment = $this->serviceAlignment->check($page);
        $mismatch = $alignment['checked'] && ! $alignment['aligned'];

        return [
            'silo' => $siloName,
            'role' => $role,
            'label' => $siloName !== null ? "{$siloName} · {$role}" : "{$role} · unassigned silo",
            'subject' => $alignment['service'],
            'mismatch' => $mismatch,
            'mismatch_note' => $mismatch ? $alignment['note'] : null,
        ];
    }

    private function role(?PageType $type): string
    {
        return match ($type) {
            PageType::Hub, PageType::Pillar => 'pillar',
            PageType::Service => 'service page',
            PageType::Location => 'location page',
            PageType::Cluster => 'cluster page',
            PageType::Home => 'home',
            default => 'page',
        };
    }

    /**
     * @return array{has_target: bool, primary: ?string, volume: ?int, difficulty: ?int, secondary: list<string>, note: ?string}
     */
    private function target(Content $page): array
    {
        $keyword = $page->target_keyword_id !== null
            ? Keyword::withoutGlobalScope(SiteScope::class)->find($page->target_keyword_id)
            : null;

        if (! $keyword instanceof Keyword) {
            return [
                'has_target' => false, 'primary' => null, 'volume' => null,
                'difficulty' => null, 'secondary' => [], 'note' => 'No keyword target set.',
            ];
        }

        return [
            'has_target' => true,
            'primary' => (string) $keyword->query,
            'volume' => $keyword->volume !== null ? (int) $keyword->volume : null,
            'difficulty' => $keyword->difficulty !== null ? (int) $keyword->difficulty : null,
            'secondary' => [], // populated when secondary terms are modeled (§5 follow-up)
            'note' => null,
        ];
    }

    /**
     * The Local Falcon slot — dark until publish. Shown empty-on-purpose, never hidden.
     *
     * @return array{available: bool, note: string}
     */
    private function performance(): array
    {
        return [
            'available' => false, // Local Falcon isn't wired; live ranking numbers light up on Grow post-publish.
            'note' => 'Rankings — tracked after publish.',
        ];
    }
}
