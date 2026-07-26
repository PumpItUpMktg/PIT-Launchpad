<?php

namespace App\ContentEngine\Reconcile;

/**
 * The structured result of an orchestrated "Rebuild & reconcile" run (§B slice 4). Each stage records
 * what it changed; a failed stage records an error and the cascade continues (failure-isolated), so the
 * report always tells the operator exactly what ran, what re-linked, and what got queued for republish.
 */
final class RebuildReport
{
    public bool $structureRebuilt = false;

    public int $spokes = 0;

    public int $pagesAdded = 0;

    public int $keywordsRebucketed = 0;

    public bool $categoriesQueued = false;

    public int $postsRerouted = 0;

    public int $postsOrphaned = 0;

    public int $postsUnchanged = 0;

    public int $townsTagged = 0;

    public int $tagsAdded = 0;

    public int $tagsRemoved = 0;

    public int $republishedPosts = 0;

    public int $republishedLocationPages = 0;

    /** @var list<array{stage: string, message: string}> */
    public array $errors = [];

    public function fail(string $stage, string $message): void
    {
        $this->errors[] = ['stage' => $stage, 'message' => $message];
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** A one-line human summary of what the cascade did. */
    public function summary(): string
    {
        $parts = [];
        if ($this->structureRebuilt) {
            $parts[] = "structure rebuilt ({$this->spokes} spokes)";
        }
        if ($this->pagesAdded > 0) {
            $parts[] = "{$this->pagesAdded} page(s) added";
        }
        $parts[] = "{$this->keywordsRebucketed} keyword(s) re-bucketed";
        if ($this->categoriesQueued) {
            $parts[] = 'categories queued';
        }
        $parts[] = "{$this->postsRerouted} post(s) re-routed";
        $parts[] = "{$this->townsTagged} post(s) town-tagged";
        $parts[] = "{$this->republishedPosts} post(s) + {$this->republishedLocationPages} location page(s) queued for republish";

        $line = ucfirst(implode(', ', $parts)).'.';
        if ($this->errors !== []) {
            $line .= ' '.count($this->errors).' stage(s) had errors: '
                .implode('; ', array_map(fn (array $e): string => $e['stage'], $this->errors)).'.';
        }

        return $line;
    }
}
