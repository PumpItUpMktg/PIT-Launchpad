<?php

namespace App\ContentEngine\Drafting;

/**
 * The single, county-scoped local angle a reactive post may use: our brick-and-mortar (storefront)
 * town as the ANCHOR, and — only when a genuinely-near serving city exists in the SAME county — one
 * optional STORY town (the spoke). The shared county is the anti-drift key: the story town can never
 * belong to a different county than the storefront, so a post never wanders (Verona ↔ Montclair, both
 * Essex; Pipersville ↔ Doylestown, both Bucks). The drafter uses this only if the story genuinely
 * applies locally — never forced.
 */
final class LocalAngle
{
    public function __construct(
        public readonly ?string $anchorTown,   // the brick-and-mortar storefront town (the tie-back)
        public readonly ?string $county,       // county name for framing (best-effort; may be null)
        public readonly ?string $storyTown,    // optional same-county serving city (the spoke)
    ) {}

    /** No usable brick-and-mortar town ⇒ no local angle at all. */
    public function allowed(): bool
    {
        return $this->anchorTown !== null && trim($this->anchorTown) !== '';
    }

    /**
     * The towns to offer the drafter (and that drive location linking + tagging): the story town, if
     * any, then the anchor — at most two, both in one county.
     *
     * @return list<string>
     */
    public function towns(): array
    {
        return array_values(array_filter(
            [$this->storyTown, $this->anchorTown],
            fn (?string $t): bool => $t !== null && trim($t) !== '',
        ));
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }
}
