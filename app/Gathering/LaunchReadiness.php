<?php

namespace App\Gathering;

use App\Enums\ConnectionProvider;
use App\Filament\Pages\Gathering\BrandStep;
use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Pages\Gathering\ConnectionsStep;
use App\Filament\Pages\Gathering\LocationsStep;
use App\Filament\Pages\Gathering\ServicesStep;
use App\Filament\Pages\Gathering\SilosStep;
use App\Filament\Pages\Gathering\VoiceStep;
use App\Guided\StepGate;
use App\Models\ArrangementFlag;
use App\Models\Connection;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\VoiceProfile;

/**
 * The step-8 launch checklist — every gather/generate output the build leans on, computed
 * straight from the models (red-until-green, §9-gate style). Three items are HARD launch
 * requirements (structure generated, flags resolved, ≥1 service — the same bar the guided
 * Plan's Approve enforced); the rest are advisory: a launch without them is legal but the
 * pages will be thinner. Each item carries the URL of the surface that fixes it.
 */
class LaunchReadiness
{
    public function __construct(private readonly StepGate $stepGate) {}

    /**
     * @return list<array{key: string, label: string, ok: bool, required: bool, detail: string, url: string|null, launch_ok: bool}>
     */
    public function checklist(Site $site): array
    {
        $trade = (string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->value('trade') ?? '');
        $confirmed = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereNotNull('confirmed_at')->exists();
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists();
        $services = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
        $voice = VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists();
        $wp = Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword)->exists();
        $flags = ArrangementFlag::query()->where('site_id', $site->id)->count();
        $brandPushed = (bool) $this->stepGate->state($site)->brand_pushed;

        return [
            $this->item('business', 'Business & trade', trim((string) $site->brand_name) !== '' && $trade !== '', false,
                $trade !== '' ? "Trade: {$trade}" : 'No trade captured — the structure seed is missing.',
                BusinessStep::getUrl()),
            $this->item('services', 'Services stated', $services > 0, true,
                $services > 0 ? "{$services} service(s)" : 'No services — nothing to build pages from.',
                ServicesStep::getUrl()),
            $this->item('locations', 'Locations & coverage', $locations > 0, false,
                $locations > 0 ? "{$locations} location(s)" : 'No locations — town pages and NAP need one.',
                LocationsStep::getUrl()),
            $this->item('voice', 'Voice profile', $voice, false,
                $voice ? 'Profile present' : 'No voice profile — drafts fall back to a generic voice.',
                VoiceStep::getUrl()),
            $this->item('wordpress', 'WordPress connected', $wp, false,
                $wp ? 'App-password connection present' : 'Not connected — pages materialize but cannot push.',
                ConnectionsStep::getUrl()),
            $this->item('brand', 'Brand pushed', $brandPushed, false,
                $brandPushed ? 'Brand applied to WordPress' : 'Not pushed — the site renders on default styles.',
                BrandStep::getUrl()),
            $this->item('structure', 'Structure generated & confirmed', $spokes && $confirmed, true,
                match (true) {
                    ! $spokes => 'Not generated — run step 8.',
                    ! $confirmed => 'Generated but not finalized — Launch will finalize it as arranged.',
                    default => 'Confirmed',
                },
                SilosStep::getUrl(),
                // Generated-but-unconfirmed still launches (implicit finalize, the Plan behavior).
                okForLaunch: $spokes),
            $this->item('flags', 'Structure flags resolved', $flags === 0, true,
                $flags === 0 ? 'No open recommendations' : "{$flags} auto-arrange recommendation(s) need a decision.",
                SilosStep::getUrl()),
        ];
    }

    /** The hard gate: every required item launch-ok. */
    public function canLaunch(Site $site): bool
    {
        foreach ($this->checklist($site) as $item) {
            if ($item['required'] && ! $item['launch_ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{key: string, label: string, ok: bool, required: bool, detail: string, url: string|null, launch_ok: bool}
     */
    private function item(string $key, string $label, bool $ok, bool $required, string $detail, ?string $url, ?bool $okForLaunch = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'required' => $required,
            'detail' => $detail,
            'url' => $url,
            'launch_ok' => $okForLaunch ?? $ok,
        ];
    }
}
