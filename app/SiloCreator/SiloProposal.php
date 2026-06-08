<?php

namespace App\SiloCreator;

use App\Enums\PageType;
use App\Enums\SiloType;

/**
 * A proposed silo, pre-commit. Immutable; operator edits return a new instance
 * via the with* helpers.
 */
final class SiloProposal
{
    /**
     * @param  list<string>  $serviceIds
     * @param  list<ClusterCandidate>  $clusters
     */
    public function __construct(
        public readonly SiloType $type,
        public readonly string $name,
        public readonly RuleSet $ruleSet,
        public readonly array $serviceIds = [],
        public readonly array $clusters = [],
        public readonly ?string $parentName = null,
        public readonly string $source = 'deterministic',
        public readonly int $supportCount = 0,
        public readonly PageType $pillarPageType = PageType::Service,
    ) {}

    public function withName(string $name): self
    {
        return new self($this->type, $name, $this->ruleSet, $this->serviceIds, $this->clusters, $this->parentName, $this->source, $this->supportCount, $this->pillarPageType);
    }

    public function withParent(?string $parentName): self
    {
        return new self($this->type, $this->name, $this->ruleSet, $this->serviceIds, $this->clusters, $parentName, $this->source, $this->supportCount, $this->pillarPageType);
    }

    public function withRuleSet(RuleSet $ruleSet): self
    {
        return new self($this->type, $this->name, $ruleSet, $this->serviceIds, $this->clusters, $this->parentName, $this->source, $this->supportCount, $this->pillarPageType);
    }

    /**
     * @param  list<ClusterCandidate>  $clusters
     */
    public function withClusters(array $clusters): self
    {
        return new self($this->type, $this->name, $this->ruleSet, $this->serviceIds, $clusters, $this->parentName, $this->source, $this->supportCount, $this->pillarPageType);
    }

    public function isServicePillar(): bool
    {
        return $this->type === SiloType::ServicePillar;
    }
}
