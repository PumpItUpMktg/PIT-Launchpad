<?php

namespace App\SiloCreator;

use App\Enums\SiloType;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The reviewable set of proposals. Supports the operator review flow
 * (accept / edit / reject / merge) before commit.
 *
 * @implements IteratorAggregate<int, SiloProposal>
 */
final class SiloProposalSet implements Countable, IteratorAggregate
{
    /**
     * @param  list<SiloProposal>  $proposals
     */
    public function __construct(private readonly array $proposals = []) {}

    /**
     * @return list<SiloProposal>
     */
    public function all(): array
    {
        return $this->proposals;
    }

    public function add(SiloProposal $proposal): self
    {
        return new self([...$this->proposals, $proposal]);
    }

    public function reject(string $name): self
    {
        return new self(array_values(array_filter($this->proposals, fn (SiloProposal $p) => $p->name !== $name)));
    }

    /**
     * @param  list<string>  $names
     */
    public function only(array $names): self
    {
        return new self(array_values(array_filter($this->proposals, fn (SiloProposal $p) => in_array($p->name, $names, true))));
    }

    /**
     * @param  callable(SiloProposal): SiloProposal  $fn
     */
    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->proposals));
    }

    public function named(string $name): ?SiloProposal
    {
        foreach ($this->proposals as $proposal) {
            if ($proposal->name === $name) {
                return $proposal;
            }
        }

        return null;
    }

    public function ofType(SiloType $type): self
    {
        return new self(array_values(array_filter($this->proposals, fn (SiloProposal $p) => $p->type === $type)));
    }

    public function getIterator(): Traversable
    {
        yield from $this->proposals;
    }

    public function count(): int
    {
        return count($this->proposals);
    }
}
