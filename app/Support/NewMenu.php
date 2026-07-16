<?php

namespace App\Support;

use App\Filament\Pages\Guided\Brand;

/**
 * The PROPOSED final menu — the Menu map's inventory reduced to only the newly designed
 * surfaces, in cutover order. This is the studio-rebuild worksheet: what the one admin menu
 * looks like after the flags default on and the legacy links retire. Derived (never
 * hand-listed) from {@see MenuMap}, so it stays true as surfaces land:
 *
 *  - **menu** — the keepers: top-level (Overview / Portfolio), the flag-gated Setup steps
 *    (1–8) and Operate boards, and the Advanced internal tools.
 *  - **pending** — items still awaiting a placement/retire decision (tag `unaddressed`),
 *    plus the one known native-rebuild hole (Brand studio — deep-linked, not rebuilt).
 *  - **retiring** — every legacy surface family-tagged `setup`/`operate`: superseded, leaves
 *    the sidebar at cutover (routes stay).
 *  - **drilldowns** — hidden routes with no menu entry, reached from inside surfaces
 *    (Proof Editor, Site Cockpit, the full keyword/silo tables, superseded resources).
 */
class NewMenu
{
    /** The final-menu group order (Setup before Operate: build first, then run). */
    private const GROUP_ORDER = ['Top level', 'Setup', 'Operate', 'Advanced'];

    public function __construct(private readonly MenuMap $map) {}

    /**
     * @return array{
     *     menu: list<array{group: string, items: list<array<string, mixed>>}>,
     *     pending: list<array<string, mixed>>,
     *     retiring: list<array<string, mixed>>,
     *     drilldowns: list<array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public function build(): array
    {
        $items = collect($this->map->build()['groups'])->flatMap(
            fn (array $g) => collect($g['items'])->map(fn (array $i) => [...$i, 'group' => $g['group']])
        );

        [$pending, $rest] = $items->partition(fn (array $i) => ($i['tag'] ?? null) === 'unaddressed');
        [$retiring, $rest] = $rest->partition(fn (array $i) => in_array($i['tag'] ?? null, ['setup', 'operate'], true));
        [$drilldowns, $keepers] = $rest->partition(fn (array $i) => $i['hidden']);

        $menu = collect(self::GROUP_ORDER)
            ->map(fn (string $group) => [
                'group' => $group,
                'items' => $keepers->where('group', $group)->sortBy('sort')->values()->all(),
            ])
            ->filter(fn (array $g) => $g['items'] !== [])
            ->values()
            ->all();

        // The one known native-rebuild hole: Brand has no new-Setup surface yet — it rides as a
        // deep link on the Launch checklist. Surfaced here so the studio rebuild can't forget it.
        $pending = $pending->values()
            ->push([
                'class' => null,
                'label' => 'Brand studio',
                'group' => 'Setup',
                'sort' => 0,
                'url' => Brand::getUrl(),
                'flag' => null,
                'hidden' => false,
                'kind' => 'rebuild',
                'tag' => 'unaddressed',
            ])
            ->all();

        return [
            'menu' => $menu,
            'pending' => $pending,
            'retiring' => $retiring->sortBy(['group', 'sort'])->values()->all(),
            'drilldowns' => $drilldowns->sortBy(['group', 'sort'])->values()->all(),
            'counts' => [
                'menu' => collect($menu)->sum(fn (array $g) => count($g['items'])),
                'pending' => count($pending),
                'retiring' => $retiring->count(),
                'drilldowns' => $drilldowns->count(),
            ],
        ];
    }
}
