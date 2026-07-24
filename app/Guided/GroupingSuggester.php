<?php

namespace App\Guided;

use App\Enums\ServicePageTreatment;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Suggests a 2-level grouping for a site's flat service list — nest related sub-services under a parent,
 * each its own page (a distinct searchable service) or a section (a minor variation / add-on). Grounded
 * through the {@see ClaudeClient} seam (faked in tests). It writes `parent_service_id` + `page_treatment`
 * onto the Service rows as an EDITABLE SUGGESTION the operator then adjusts — it rebuilds no structure and
 * touches no live page. Respects the Section default: only a clearly distinct standalone service is
 * suggested as its own Page. A parse/again failure groups nothing — never fatal to the step.
 */
class GroupingSuggester
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /** @return int the number of services nested under a parent */
    public function suggest(Site $site): int
    {
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNull('parent_service_id')
            ->orderBy('name')
            ->get();

        if ($services->count() < 2) {
            return 0;
        }

        $byKey = $services->keyBy(fn (Service $s): string => $this->key((string) $s->name));

        $system = 'You organize a local home-services company\'s services into a two-level menu. '
            .'Group closely-related services under a parent. A sub-service is its own PAGE only if it is a '
            .'distinct thing a customer would search for on its own; otherwise it is a SECTION (a minor '
            .'variation, add-on, or detail of the parent). Prefer sections. Never invent services. '
            .'Reply ONLY with JSON: [{"parent":"<name>","children":[{"name":"<name>","treatment":"page|section"}]}].';
        $prompt = "Services:\n".$services->pluck('name')->implode("\n");

        $grouped = 0;
        foreach ($this->parse($this->safeComplete($prompt, $system)) as $group) {
            $parent = $byKey->get($this->key($group['parent']));
            // Parent must be a real, still-top-level service (not itself nested by an earlier group).
            if ($parent === null || $parent->parent_service_id !== null) {
                continue;
            }

            foreach ($group['children'] as $childRow) {
                $child = $byKey->get($this->key($childRow['name']));
                // 2-level cap + no self-nesting + don't re-home an already-grouped service.
                if ($child === null || $child->id === $parent->id || $child->parent_service_id !== null || $child->childServices()->exists()) {
                    continue;
                }

                $child->forceFill([
                    'parent_service_id' => $parent->id,
                    'page_treatment' => $childRow['treatment'] === 'page'
                        ? ServicePageTreatment::Page
                        : ServicePageTreatment::Section,
                ])->save();
                $grouped++;
            }
        }

        return $grouped;
    }

    private function safeComplete(string $prompt, string $system): string
    {
        try {
            return $this->claude->complete($prompt, $system);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<array{parent: string, children: list<array{name: string, treatment: string}>}>
     */
    private function parse(string $raw): array
    {
        // Tolerate prose/fences around the JSON; the outer array spans first '[' to LAST ']' (children
        // are nested arrays, so stopping at the first ']' would truncate the payload).
        $raw = trim(Str::of($raw)->after('[')->beforeLast(']')->prepend('[')->append(']')->value());
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $group) {
            if (! is_array($group) || trim((string) ($group['parent'] ?? '')) === '') {
                continue;
            }
            $children = [];
            foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $child) {
                if (is_array($child) && trim((string) ($child['name'] ?? '')) !== '') {
                    $children[] = ['name' => (string) $child['name'], 'treatment' => (string) ($child['treatment'] ?? 'section')];
                }
            }
            if ($children !== []) {
                $out[] = ['parent' => (string) $group['parent'], 'children' => $children];
            }
        }

        return $out;
    }

    private function key(string $value): string
    {
        return Str::of($value)->lower()->squish()->value();
    }
}
