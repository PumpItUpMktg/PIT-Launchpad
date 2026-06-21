<?php

namespace App\Filament\Pages\Guided;

use App\Build\BuildManifestAssembler;
use App\Build\BuildRunner;
use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\BuildPage;
use Filament\Notifications\Notification;

/**
 * The Build phase — between Approve and Grow. Renders the build manifest grouped by source
 * (Standard / Service / Location) with per-page status, a review queue for the brand-critical
 * gated pages, and overall progress. On entry it advances the manifest via {@see BuildRunner}
 * (the stub seam — auto pages publish, gated pages park in review). Once the foundation is live
 * (review-gated + fixed standard published) the site is launched and hands off to Grow.
 *
 * @property-read array<string, list<BuildPage>> $manifest
 * @property-read list<BuildPage> $reviewQueue
 * @property-read array{published: int, total: int, pct: int} $progress
 * @property-read bool $launched
 */
class Build extends GuidedPage
{
    protected static ?string $slug = 'build';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Build';

    protected string $view = 'filament.guided.build';

    public function step(): SetupStep
    {
        return SetupStep::Build;
    }

    public function mount(): void
    {
        parent::mount(); // gate: requires approved

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (BuildPage::query()->where('site_id', $site->id)->doesntExist()) {
            app(BuildManifestAssembler::class)->assemble($site); // safety: assemble if not already
        }

        app(BuildRunner::class)->tick($site); // advance the queue (auto publish / gate to review)
    }

    /**
     * @return array<string, list<BuildPage>>
     */
    public function getManifestProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return [];
        }

        $grouped = [];
        foreach (BuildSource::cases() as $source) {
            $pages = BuildPage::query()
                ->where('site_id', $site->id)->where('source', $source->value)
                ->orderBy('priority')->orderBy('title')
                ->get()->all();
            if ($pages !== []) {
                $grouped[$source->label()] = $pages;
            }
        }

        return $grouped;
    }

    /**
     * @return list<BuildPage>
     */
    public function getReviewQueueProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : BuildPage::query()
            ->where('site_id', $site->id)->where('status', BuildStatus::InReview->value)
            ->orderBy('priority')->get()->all();
    }

    /**
     * @return array{published: int, total: int, pct: int}
     */
    public function getProgressProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return ['published' => 0, 'total' => 0, 'pct' => 0];
        }

        $base = BuildPage::query()->where('site_id', $site->id);
        $total = (clone $base)->count();
        $published = (clone $base)->where('status', BuildStatus::Published->value)->count();

        return [
            'published' => $published,
            'total' => $total,
            'pct' => $total > 0 ? (int) round($published / $total * 100) : 0,
        ];
    }

    public function getLaunchedProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(BuildRunner::class)->launchReady($site);
    }

    /** Publish a reviewed brand-critical page. */
    public function publishReviewed(string $id): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $page = BuildPage::query()->where('site_id', $site->id)->whereKey($id)->first();
        if ($page !== null && app(BuildRunner::class)->publishReviewed($site, $page)) {
            Notification::make()->title($page->title.' published.')->success()->send();
        }
    }

    public function continueToGrow(): void
    {
        $site = $this->getSite();
        if ($site === null || ! app(BuildRunner::class)->launchReady($site)) {
            Notification::make()->title('Review the brand-critical pages first.')->warning()->send();

            return;
        }

        // launchReady ⇒ reconcileLaunch already set launched + flipped status to Active; advance
        // current_step to Grow so the state/stepper stay coherent with the handoff.
        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Build);

        $this->redirect(SetupStep::Grow->pageClass()::getUrl());
    }
}
