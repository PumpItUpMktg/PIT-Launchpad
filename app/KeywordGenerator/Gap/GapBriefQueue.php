<?php

namespace App\KeywordGenerator\Gap;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Gap-briefs ordered by the quick-wins lane (high-value + fast-to-rank first).
 *
 * @implements IteratorAggregate<int, GapBrief>
 */
final class GapBriefQueue implements Countable, IteratorAggregate
{
    /** @var list<GapBrief> */
    private array $briefs;

    /**
     * @param  list<GapBrief>  $briefs
     */
    public function __construct(array $briefs)
    {
        usort($briefs, fn (GapBrief $a, GapBrief $b) => $b->quickWin <=> $a->quickWin);
        $this->briefs = $briefs;
    }

    /**
     * @return list<GapBrief>
     */
    public function all(): array
    {
        return $this->briefs;
    }

    public function first(): ?GapBrief
    {
        return $this->briefs[0] ?? null;
    }

    public function getIterator(): Traversable
    {
        yield from $this->briefs;
    }

    public function count(): int
    {
        return count($this->briefs);
    }
}
