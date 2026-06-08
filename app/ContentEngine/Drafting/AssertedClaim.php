<?php

namespace App\ContentEngine\Drafting;

/**
 * A business assertion the model says it made, tagged with the Claims-pool id it
 * was drawn from. The verification pass traces each one back to the pool; a null
 * or unknown id is what marks an assertion unsupported.
 */
final class AssertedClaim
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $claimId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['claim_id'] ?? null;

        return new self(
            text: (string) ($data['text'] ?? ''),
            claimId: ($id === null || $id === '') ? null : (string) $id,
        );
    }
}
