<?php

namespace App\PageBuilder\Schema;

use App\Enums\SlotContentType;
use App\Enums\SlotRole;
use App\Enums\SlotSource;

/**
 * A single typed slot in a kit: its content type, framing role, source, and the
 * constraints generation/validation must satisfy.
 */
final class SlotDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly SlotContentType $contentType,
        public readonly SlotRole $role,
        public readonly SlotSource $source,
        public readonly Cardinality $cardinality,
        public readonly SlotConstraints $constraints,
        public readonly bool $clientOverride = false,
        public readonly ?string $generationHint = null,
        public readonly ?string $seoBinding = null,
        public readonly ?string $elementorBinding = null,
        public readonly ?SlotCondition $condition = null,
    ) {}

    public function isRepeater(): bool
    {
        return $this->cardinality->isRepeater();
    }

    public function isRequired(): bool
    {
        return $this->constraints->required;
    }

    public function isProof(): bool
    {
        return $this->role === SlotRole::Proof;
    }

    /**
     * Whether this slot applies given the page's context flags (e.g. storefront).
     *
     * @param  array<string, mixed>  $flags
     */
    public function appliesTo(array $flags): bool
    {
        return $this->condition === null || $this->condition->matches($flags);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) ($data['label'] ?? $data['key']),
            contentType: SlotContentType::from((string) $data['content_type']),
            role: SlotRole::from((string) $data['role']),
            source: SlotSource::from((string) $data['source']),
            cardinality: isset($data['cardinality']) && is_array($data['cardinality'])
                ? Cardinality::fromArray($data['cardinality'])
                : Cardinality::single(),
            constraints: isset($data['constraints']) && is_array($data['constraints'])
                ? SlotConstraints::fromArray($data['constraints'])
                : new SlotConstraints,
            clientOverride: (bool) ($data['client_override'] ?? false),
            generationHint: isset($data['generation_hint']) ? (string) $data['generation_hint'] : null,
            seoBinding: isset($data['seo_binding']) ? (string) $data['seo_binding'] : null,
            elementorBinding: isset($data['elementor_binding']) ? (string) $data['elementor_binding'] : null,
            condition: isset($data['condition']) && is_array($data['condition'])
                ? SlotCondition::fromArray($data['condition'])
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'key' => $this->key,
            'label' => $this->label,
            'content_type' => $this->contentType->value,
            'role' => $this->role->value,
            'source' => $this->source->value,
            'cardinality' => $this->cardinality->toArray(),
            'constraints' => $this->constraints->toArray(),
            'client_override' => $this->clientOverride,
        ];

        if ($this->generationHint !== null) {
            $out['generation_hint'] = $this->generationHint;
        }
        if ($this->seoBinding !== null) {
            $out['seo_binding'] = $this->seoBinding;
        }
        if ($this->elementorBinding !== null) {
            $out['elementor_binding'] = $this->elementorBinding;
        }
        if ($this->condition !== null) {
            $out['condition'] = $this->condition->toArray();
        }

        return $out;
    }
}
