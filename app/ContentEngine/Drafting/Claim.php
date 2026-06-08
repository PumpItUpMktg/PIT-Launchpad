<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ProofType;
use App\Models\ProofItem;

/**
 * A single substantiated business claim drawn from the Proof set — the ONLY
 * pool a business assertion may come from. Carries a stable id so the
 * verification pass can trace each asserted claim back to its source of truth.
 */
final class Claim
{
    public function __construct(
        public readonly string $id,
        public readonly ProofType $type,
        public readonly string $text,
        public readonly ?string $evidence = null,
    ) {}

    public static function fromProofItem(ProofItem $item): self
    {
        $payload = $item->payload ?? [];
        $text = (string) ($payload['label'] ?? $payload['text'] ?? $item->type->value);

        return new self(
            id: $item->id,
            type: $item->type,
            text: $text,
            evidence: $item->evidence,
        );
    }

    /**
     * The line handed to the model: the claim, tagged with its id so the model
     * can cite exactly which claim it asserted.
     */
    public function promptLine(): string
    {
        return "[{$this->id}] ({$this->type->value}) {$this->text}";
    }
}
