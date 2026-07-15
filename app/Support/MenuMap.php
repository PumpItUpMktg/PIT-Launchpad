<?php

namespace App\Support;

use Filament\Facades\Filament;
use Throwable;

/**
 * The full admin-menu inventory, enumerated PROGRAMMATICALLY from the panel's discovered pages +
 * resources (nothing hand-listed, so nothing can be missed). Both parallel-build flags are forced
 * on during enumeration so the flag-gated Setup/Operate groups appear alongside the old menu;
 * each entry records which flag (if any) it needs, whether it's hidden from the nav outright
 * (superseded surfaces with routes kept), and its sort — the raw material for deciding the ONE
 * final menu order at cutover.
 */
class MenuMap
{
    private const FLAGS = [
        'launchpad.new_setup_enabled' => 'NEW_SETUP',
        'launchpad.new_operate_enabled' => 'NEW_OPERATE',
    ];

    /**
     * @return array{groups: list<array{group: string, items: list<array<string, mixed>>}>, duplicates: list<string>, counts: array<string, int>}
     */
    public function build(): array
    {
        $entries = $this->withFlagsOn(fn (): array => $this->enumerate());

        // Group in the panel's configured order; unknown groups follow; Top level leads.
        $configured = ['Top level', 'Operate', 'Setup', 'Local Blog', 'Live', 'Targeting', 'Settings', 'Advanced'];
        $byGroup = collect($entries)->groupBy('group');
        $order = collect($configured)
            ->concat($byGroup->keys()->reject(fn ($g) => in_array($g, $configured, true))->sort())
            ->filter(fn ($g) => $byGroup->has($g))
            ->values();

        $groups = $order->map(fn (string $group) => [
            'group' => $group,
            'items' => $byGroup->get($group)->sortBy('sort')->values()->all(),
        ])->all();

        // Same label in more than one place — the ordering discussion needs to see these.
        $duplicates = collect($entries)
            ->groupBy(fn (array $e) => mb_strtolower($e['label']))
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->first()['label'].' — '.$g->map(fn ($e) => $e['group'])->join(' + '))
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'duplicates' => $duplicates,
            'counts' => [
                'total' => count($entries),
                'visible' => collect($entries)->where('hidden', false)->count(),
                'hidden' => collect($entries)->where('hidden', true)->count(),
                'flagged' => collect($entries)->whereNotNull('flag')->count(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enumerate(): array
    {
        $panel = Filament::getPanel('admin');
        $classes = array_values(array_unique([...$panel->getPages(), ...$panel->getResources()]));

        $out = [];
        foreach ($classes as $class) {
            $registers = (bool) $class::shouldRegisterNavigation();

            $out[] = [
                'class' => class_basename($class),
                'label' => (string) ($class::getNavigationLabel() ?? class_basename($class)),
                'group' => $this->groupName($class),
                'sort' => $class::getNavigationSort() ?? 0,
                'url' => $this->url($class),
                'flag' => $registers ? $this->requiredFlag($class) : null,
                'hidden' => ! $registers,
                'kind' => str_contains($class, '\\Resources\\') ? 'resource' : 'page',
            ];
        }

        return $out;
    }

    private function groupName(string $class): string
    {
        $group = $class::getNavigationGroup();
        if ($group === null || $group === '') {
            return 'Top level';
        }

        return $group instanceof \UnitEnum ? $group->name : (string) $group;
    }

    private function url(string $class): ?string
    {
        try {
            return method_exists($class, 'getUrl') && ! str_contains($class, '\\Resources\\')
                ? $class::getUrl()
                : $class::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }

    /** Which parallel-build flag (if any) this entry's nav registration depends on. */
    private function requiredFlag(string $class): ?string
    {
        foreach (self::FLAGS as $key => $label) {
            $original = config($key);
            config([$key => false]);
            $without = (bool) $class::shouldRegisterNavigation();
            config([$key => $original]);

            if (! $without) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withFlagsOn(callable $callback)
    {
        $original = [];
        foreach (array_keys(self::FLAGS) as $key) {
            $original[$key] = config($key);
            config([$key => true]);
        }

        try {
            return $callback();
        } finally {
            config($original);
        }
    }
}
