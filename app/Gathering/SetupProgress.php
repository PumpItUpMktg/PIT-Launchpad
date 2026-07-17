<?php

namespace App\Gathering;

use App\Enums\ConnectionProvider;
use App\Enums\InterviewStatus;
use App\Enums\VoiceStatus;
use App\Filament\Pages\Gathering\BrandStep;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\ConnectionsStep;
use App\Filament\Pages\Gathering\InterviewStep;
use App\Filament\Pages\Gathering\LaunchStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Filament\Pages\Gathering\ServicesStep;
use App\Filament\Pages\Gathering\SilosStep;
use App\Filament\Pages\Gathering\VoiceStep;
use App\Guided\StepGate;
use App\Models\Connection;
use App\Models\Interview;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;

/**
 * The new Setup's step ladder — one ordered list of the nine steps with a cheap per-step
 * done-state computed straight from the models, powering the in-page stepper rail and the
 * run-once-but-returnable resume (the Setup entry lands on the first unfinished REQUIRED
 * step; the interview is optional and never traps the resume). Steps are never gated —
 * the rail links everywhere; done is state, not a wall.
 */
class SetupProgress
{
    /**
     * @return list<array{n: int, class: class-string, label: string, url: string, done: bool, optional: bool, current: bool}>
     */
    public function steps(Site $site, ?string $currentClass = null): array
    {
        $wp = Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword)->exists();
        $trade = trim((string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->value('trade') ?? ''));
        $state = app(StepGate::class)->state($site);

        $rows = [
            [BusinessStep::class, 'Business', trim((string) $site->brand_name) !== '' && $trade !== '', false],
            [InterviewStep::class, 'Interview', Interview::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('status', InterviewStatus::Complete->value)->exists(), true],
            [LocationsStep::class, 'Locations', Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists(), false],
            [ServicesStep::class, 'Services', Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists(), false],
            [VoiceStep::class, 'Voice', VoiceProfile::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('status', VoiceStatus::Active->value)->exists(), false],
            [ConnectionsStep::class, 'Connections & Feeds', $wp, false],
            [BrandStep::class, 'Brand', (bool) $state->brand_pushed, false],
            [SilosStep::class, 'Silos & keywords', SiloBlueprint::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->whereNotNull('confirmed_at')->exists(), false],
            [LaunchStep::class, 'Launch', (bool) $state->launched, false],
        ];

        $steps = [];
        foreach ($rows as $i => [$class, $label, $done, $optional]) {
            $steps[] = [
                'n' => $i + 1,
                'class' => $class,
                'label' => $label,
                'url' => $class::getUrl(),
                'done' => $done,
                'optional' => $optional,
                'current' => $currentClass === $class,
            ];
        }

        return $steps;
    }

    /**
     * Where a returning operator lands: the first unfinished required step; a fully-done
     * setup lands on Launch (the checklist is the natural home afterwards).
     *
     * @return class-string
     */
    public function resumeStep(Site $site): string
    {
        foreach ($this->steps($site) as $step) {
            if (! $step['optional'] && ! $step['done']) {
                return $step['class'];
            }
        }

        return LaunchStep::class;
    }
}
