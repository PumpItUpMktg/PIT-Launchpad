<?php

namespace App\Standard;

use App\Enums\StandardPageType;
use App\Guided\StepGate;
use App\Models\Site;

/**
 * The standard-pages config for a site: which fixed pages are always built, which optionals are
 * currently offerable ({@see StandardPageGate}), and which the client has accepted (persisted on
 * the SetupState `standard_pages` map). The accepted set is intersected with the offerable set on
 * read, so an optional whose data later disappears is never treated as accepted.
 */
class StandardPages
{
    public function __construct(
        private readonly StandardPageGate $gate,
        private readonly StepGate $steps,
    ) {}

    /**
     * The optional pages on offer right now, each with its accepted state.
     *
     * @return list<array{type: StandardPageType, accepted: bool}>
     */
    public function offerable(Site $site): array
    {
        $accepted = $this->acceptedMap($site);

        return array_map(
            fn (StandardPageType $t) => ['type' => $t, 'accepted' => (bool) ($accepted[$t->value] ?? false)],
            $this->gate->offerable($site),
        );
    }

    /** Record the client's accept/decline of an optional page (offerable types only). */
    public function setAccepted(Site $site, StandardPageType $type, bool $accepted): void
    {
        if ($type->isFixed() || ! $this->gate->isAvailable($site, $type)) {
            return;
        }

        $state = $this->steps->state($site);
        $map = is_array($state->standard_pages) ? $state->standard_pages : [];
        $map[$type->value] = $accepted;
        $state->update(['standard_pages' => $map]);
    }

    /**
     * Every standard page that will be built: the fixed core + the accepted, still-offerable
     * optionals — the standard slice of the build manifest.
     *
     * @return list<StandardPageType>
     */
    public function forSite(Site $site): array
    {
        $accepted = $this->acceptedMap($site);

        $optionals = array_filter(
            $this->gate->offerable($site),
            fn (StandardPageType $t) => (bool) ($accepted[$t->value] ?? false),
        );

        return [...StandardPageType::fixed(), ...array_values($optionals)];
    }

    /**
     * @return array<string, bool>
     */
    private function acceptedMap(Site $site): array
    {
        $state = $site->setupState;

        return $state !== null && is_array($state->standard_pages) ? $state->standard_pages : [];
    }
}
