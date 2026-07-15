<?php

namespace App\Gathering;

use App\Integrations\Claude\ClaudeClient;
use App\Interview\SiloSeed;
use App\Locations\ServedTowns;
use App\Models\Interview;
use App\Models\InterviewTurn;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\VoiceProfile;

/**
 * The extraction pass (gathering relay): one structured Claude call — transcript → JSON mapped to
 * the REAL schema — then seeding with provenance. Re-runnable at any time: every write goes
 * through {@see Provenance::canSeed()}, so a `confirmed` field is never overwritten and a re-run
 * updates only seeded/empty fields. Served-town candidates respect the one-town-one-location
 * guard — conflicting candidates (and unresolvable coverage phrases) land on the location's
 * `coverage_suggestions` as operator prompts, never as saved rows.
 */
class IntakeExtractor
{
    private const TRUST_FIELDS = ['license_number', 'insured', 'years_in_business', 'warranty_program', 'guarantees'];

    private const SERVICE_FIELDS = ['short_description', 'symptoms', 'scope_items', 'process_steps', 'cost_factors', 'price_range'];

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly Provenance $provenance,
        private readonly ServedTowns $servedTowns,
    ) {}

    /**
     * @return array{trust: int, services: int, locations: int, suggestions: int, voice: bool}
     */
    public function extract(Interview $interview): array
    {
        $site = $interview->site;
        $raw = $this->claude->complete($this->prompt($interview), $this->system($site));
        $data = $this->parse($raw);

        $trust = $this->seedTrustFacts($site, (array) ($data['trust_facts'] ?? []));
        $services = $this->seedServices($site, (array) ($data['services'] ?? []));
        $this->seedSiloSeed($site, trim((string) ($data['trade'] ?? '')), (array) ($data['services'] ?? []));
        [$locations, $suggestions] = $this->seedCoverage($site, (array) ($data['coverage'] ?? []), (array) ($data['market_notes'] ?? []));
        $voice = $this->seedVoice($site, (array) ($data['voice'] ?? []));

        return [
            'trust' => $trust,
            'services' => $services,
            'locations' => $locations,
            'suggestions' => $suggestions,
            'voice' => $voice,
        ];
    }

    private function system(Site $site): string
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->pluck('name')->join('; ');

        return <<<PROMPT
You extract structured intake data from an owner-interview transcript for {$site->brand_name}.
Known locations (use these exact names as the "location" keys): {$locations}

Respond with ONLY a JSON object in this exact shape (omit anything the transcript does not state — NEVER invent values):
{
  "trade": "<the business's trade in 2-4 words, e.g. 'basement waterproofing' — from the transcript>",
  "trust_facts": {"license_number": "...", "insured": true, "years_in_business": 12, "warranty_program": "...", "guarantees": "..."},
  "services": [{"name": "...", "short_description": "...", "symptoms": ["..."], "scope_items": ["..."], "process_steps": ["..."], "cost_factors": ["..."], "price_range": {"low": 0, "high": 0, "unit": "..."}}],
  "coverage": [{"location": "<known location name>", "towns": ["Town, ST"], "unresolved": ["<fuzzy phrase that could not be resolved to town names>"]}],
  "market_notes": [{"location": "<known location name>", "notes": "..."}],
  "voice": {"persona": "...", "language_rules": ["phrasing they use", "words they would never use: ..."], "audience": ["..."], "cta_voice": "...", "reading_level": "..."}
}

Rules: services must be the exhaustive stated list, including zero-search-volume work. Towns must be concrete "Town, ST" names — keep fuzzy coverage answers ("30 min from the shop") in "unresolved" verbatim. Market notes are the owner's local knowledge only, per location.
PROMPT;
    }

    private function prompt(Interview $interview): string
    {
        $lines = $interview->turns()->get()->map(function (InterviewTurn $turn) {
            $who = $turn->role === 'assistant' ? 'INTERVIEWER' : 'OWNER';

            return "{$who}: {$turn->content}";
        })->implode("\n");

        return "TRANSCRIPT:\n{$lines}\n\nExtract the JSON now.";
    }

    /**
     * The STRUCTURE seed (SiloBlueprint trade + anchor services) — the old Owner Interview's
     * remaining job, folded into this extraction. Never touches a CONFIRMED blueprint
     * (confirmed_at set = the structure was committed); per-field provenance guards re-runs.
     *
     * @param  list<array<string, mixed>>  $services
     */
    private function seedSiloSeed(Site $site, string $trade, array $services): void
    {
        $anchor = collect($services)
            ->map(fn ($row) => trim((string) ($row['name'] ?? '')))
            ->filter()
            ->values()
            ->all();
        if ($trade === '' && $anchor === []) {
            return;
        }

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);
        if ($blueprint->confirmed_at !== null) {
            return; // the structure is committed — extraction never reseeds under it
        }

        $seed = (array) ($blueprint->seed ?? []);
        $updates = [];
        if ($trade !== '' && $this->provenance->canSeed($blueprint, 'trade')) {
            $seed['trade'] = $trade;
            $updates['trade'] = $trade;
            $this->provenance->seed($blueprint, 'trade');
        }
        if ($anchor !== [] && $this->provenance->canSeed($blueprint, 'anchor_services')) {
            $seed['anchor_services'] = $anchor;
            $this->provenance->seed($blueprint, 'anchor_services');
        }

        if ($updates !== [] || ($seed !== (array) ($blueprint->seed ?? []))) {
            $silo = SiloSeed::fromArray($seed);
            $blueprint->update([...$updates, 'seed' => [...$silo->toArray(), 'suggested_confirmed' => ($seed['suggested_confirmed'] ?? [])]]);
        }
    }

    private function seedTrustFacts(Site $site, array $facts): int
    {
        $written = 0;
        foreach (self::TRUST_FIELDS as $field) {
            if (! array_key_exists($field, $facts) || $facts[$field] === null || $facts[$field] === '') {
                continue;
            }
            if (! $this->provenance->canSeed($site, $field)) {
                continue; // operator-confirmed — never clobbered
            }

            $site->forceFill([$field => $facts[$field]])->save();
            $this->provenance->seed($site, $field);
            $written++;
        }

        return $written;
    }

    /**
     * @param  list<array<string, mixed>>  $services
     */
    private function seedServices(Site $site, array $services): int
    {
        $existing = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $touched = 0;

        foreach ($services as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $service = $existing->first(fn (Service $s) => strcasecmp(trim((string) $s->name), $name) === 0);
            if ($service === null) {
                $service = new Service;
                $service->forceFill(['site_id' => $site->id, 'name' => $name])->save();
                $this->provenance->seed($service, 'name');
                $existing->push($service);
            }

            $wrote = false;
            foreach (self::SERVICE_FIELDS as $field) {
                $value = $row[$field] ?? null;
                if ($value === null || $value === [] || $value === '') {
                    continue;
                }
                if (! $this->provenance->canSeed($service, $field)) {
                    continue;
                }

                $service->forceFill([$field => $value])->save();
                $this->provenance->seed($service, $field);
                $wrote = true;
            }

            if ($wrote) {
                $touched++;
            }
        }

        return $touched;
    }

    /**
     * Served-town candidates + market notes, per location. Candidates already served by ANOTHER
     * location (the one-town-one-location guard) — and every unresolved phrase — land on
     * `coverage_suggestions` for the operator, never as saved rows.
     *
     * @param  list<array<string, mixed>>  $coverage
     * @param  list<array<string, mixed>>  $notes
     * @return array{0: int, 1: int} locations touched, suggestion entries written
     */
    private function seedCoverage(Site $site, array $coverage, array $notes): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $notesByLocation = collect($notes)->keyBy(fn ($n) => mb_strtolower(trim((string) ($n['location'] ?? ''))));

        $touched = 0;
        $suggestionCount = 0;

        foreach ($locations as $location) {
            $key = mb_strtolower(trim((string) $location->name));
            $entry = collect($coverage)->first(fn ($c) => mb_strtolower(trim((string) ($c['location'] ?? ''))) === $key);
            $wrote = false;

            // Towns → served_towns (seeded), conflicts → suggestions.
            if ($entry !== null && $this->provenance->canSeed($location, 'served_towns')) {
                $candidates = collect((array) ($entry['towns'] ?? []))
                    ->map(fn ($t) => trim((string) $t))->filter()->unique()->values();

                if ($candidates->isNotEmpty()) {
                    $conflicts = $this->servedTowns->conflicts($site->id, $candidates->all(), $location->id);
                    $conflicted = collect($conflicts)->map(fn (array $c) => mb_strtolower((string) $c['town']));

                    $current = collect($location->served_towns ?? []);
                    $currentKeys = $current->map(fn ($t) => mb_strtolower(trim((string) ($t['name'] ?? '')).', '.trim((string) ($t['state'] ?? ''))));

                    $clean = $candidates->reject(function (string $town) use ($conflicted, $currentKeys) {
                        $lower = mb_strtolower($town);

                        return $conflicted->contains(fn ($c) => $c !== '' && str_contains($lower, $c) || str_contains((string) $c, $lower))
                            || $currentKeys->contains($lower);
                    });

                    if ($clean->isNotEmpty()) {
                        $rows = $clean->map(function (string $town) {
                            [$name, $state] = array_pad(array_map('trim', explode(',', $town, 2)), 2, '');

                            return ['name' => $name, 'state' => $state, 'lat' => null, 'lng' => null, 'geocoded' => false];
                        });
                        $location->forceFill(['served_towns' => $current->concat($rows)->values()->all()])->save();
                        $this->provenance->seed($location, 'served_towns');
                        $wrote = true;
                    }

                    $conflictedTowns = $candidates->filter(function (string $town) use ($conflicted) {
                        $lower = mb_strtolower($town);

                        return $conflicted->contains(fn ($c) => $c !== '' && (str_contains($lower, (string) $c) || str_contains((string) $c, $lower)));
                    })->values()->all();
                    $suggestionCount += $this->appendSuggestions($location, $conflictedTowns, (array) ($entry['unresolved'] ?? []));
                } else {
                    $suggestionCount += $this->appendSuggestions($location, [], (array) ($entry['unresolved'] ?? []));
                }
            } elseif ($entry !== null) {
                // served_towns confirmed — candidates become suggestions instead of writes.
                $suggestionCount += $this->appendSuggestions($location, (array) ($entry['towns'] ?? []), (array) ($entry['unresolved'] ?? []));
            }

            // Market notes.
            $note = $notesByLocation->get($key);
            $noteText = trim((string) ($note['notes'] ?? ''));
            if ($noteText !== '' && $this->provenance->canSeed($location, 'market_notes')) {
                $location->forceFill(['market_notes' => $noteText])->save();
                $this->provenance->seed($location, 'market_notes');
                $wrote = true;
            }

            if ($wrote) {
                $touched++;
            }
        }

        return [$touched, $suggestionCount];
    }

    /**
     * @param  list<mixed>  $towns
     * @param  list<mixed>  $phrases
     */
    private function appendSuggestions(Location $location, array $towns, array $phrases): int
    {
        $towns = collect($towns)->map(fn ($t) => trim((string) $t))->filter()->values();
        $phrases = collect($phrases)->map(fn ($p) => trim((string) $p))->filter()->values();
        if ($towns->isEmpty() && $phrases->isEmpty()) {
            return 0;
        }

        $current = (array) ($location->coverage_suggestions ?? []);
        $merged = [
            'towns' => collect((array) ($current['towns'] ?? []))->concat($towns)->unique()->values()->all(),
            'phrases' => collect((array) ($current['phrases'] ?? []))->concat($phrases)->unique()->values()->all(),
        ];
        $location->forceFill(['coverage_suggestions' => $merged])->save();

        return $towns->count() + $phrases->count();
    }

    /**
     * Voice draft: create (or update) THE extraction-seeded Draft profile — never an Active one,
     * and re-runs update the same seeded draft rather than stacking versions.
     */
    private function seedVoice(Site $site, array $voice): bool
    {
        $values = array_filter([
            'persona' => $this->voicePersona($voice),
            'language_rules' => (array) ($voice['language_rules'] ?? []) ?: null,
            'audience' => (array) ($voice['audience'] ?? []) ?: null,
            'reading_level' => trim((string) ($voice['reading_level'] ?? '')) ?: null,
            'cta_voice' => trim((string) ($voice['cta_voice'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        if ($values === []) {
            return false;
        }

        $draft = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', 'draft')
            ->orderByDesc('version')
            ->first();

        if ($draft !== null && ! $this->provenance->canSeed($draft, 'profile')) {
            return false; // operator confirmed the draft — leave it alone
        }

        if ($draft === null) {
            $max = (int) VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->max('version');
            $draft = new VoiceProfile;
            $draft->forceFill([
                'site_id' => $site->id,
                'version' => $max + 1,
                'status' => 'draft',
                'framing_model' => 'problem_solution',
            ]);
        }

        $draft->forceFill($values)->save();
        $this->provenance->seed($draft, 'profile');

        return true;
    }

    /**
     * @return array<string, string>|null
     */
    private function voicePersona(array $voice): ?array
    {
        $persona = trim((string) ($voice['persona'] ?? ''));

        return $persona === '' ? null : ['description' => $persona];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $raw): array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)) ?? $raw);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
