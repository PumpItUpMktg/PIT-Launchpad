<?php

namespace App\SiloCreator;

/**
 * A candidate cluster under a pillar silo — a topic derived from a supporting
 * service or a customer problem. §4 emits these for operator review and the §5
 * handoff; §5 turns scored keyword targets into actual cluster pages.
 */
final class ClusterCandidate
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $serviceId = null,
        public readonly ?string $problemId = null,
        public readonly string $source = 'service_problem',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'service_id' => $this->serviceId,
            'problem_id' => $this->problemId,
            'source' => $this->source,
        ];
    }
}
