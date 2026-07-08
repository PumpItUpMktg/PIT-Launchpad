<?php

namespace App\Standard;

use App\Enums\StandardPageType;
use App\Models\WireframeKit;

/**
 * Resolves the wireframe kit for a standard page. Service/location kits resolve by page_type (one
 * kit each), but standard pages share the coarse Utility/Home page_type, so they resolve by NAME —
 * a stable map from {@see StandardPageType} to its library kit name.
 *
 * Only the standard pages whose composer has shipped appear in {@see KITS}; the rest return null and
 * stay held ("Not ready yet") until their kit (and, where needed, data source) lands. This is the
 * single place that map lives, so the materializer and the readiness gate agree on what's buildable.
 */
final class StandardKit
{
    /** StandardPageType value → library kit name, for the standard pages whose composer has shipped. */
    private const KITS = [
        'home' => 'home-page',
        'about' => 'about-page',
        'why_choose_us' => 'why-choose-us-page',
        'areas_we_serve' => 'areas-we-serve-page',
        'faq' => 'faq-page',
    ];

    /** The library kit name for a standard page, or null if its composer hasn't shipped. */
    public static function nameFor(StandardPageType $type): ?string
    {
        return self::KITS[$type->value] ?? null;
    }

    /** Whether the standard-page composer can build this type today. */
    public static function isComposable(StandardPageType $type): bool
    {
        return self::nameFor($type) !== null;
    }

    /** Resolve the library kit (a per-site override wins) for a standard page, or null. */
    public static function resolve(StandardPageType $type, ?string $siteId = null): ?WireframeKit
    {
        $name = self::nameFor($type);
        if ($name === null) {
            return null;
        }

        if ($siteId !== null) {
            $override = WireframeKit::query()
                ->where('name', $name)
                ->where('site_id', $siteId)
                ->orderByDesc('version')
                ->first();

            if ($override !== null) {
                return $override;
            }
        }

        return WireframeKit::query()
            ->where('name', $name)
            ->whereNull('site_id')
            ->orderByDesc('version')
            ->first();
    }
}
