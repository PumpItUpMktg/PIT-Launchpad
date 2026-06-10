<?php

namespace App\PageBuilder\Schema;

use App\Enums\PageType;
use App\Enums\SlotRole;

/**
 * The typed, in-memory representation of a WireframeKit's slot_schema: kit-level
 * metadata plus an ordered, key-addressable set of slot definitions. Round-trips
 * losslessly to/from the WireframeKit.slot_schema JSON column.
 */
final class KitSchema
{
    /**
     * @param  array<int, SlotDefinition>  $slots  ordered slot definitions
     */
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly ?PageType $pageType,
        public readonly array $slots,
        public readonly ?string $elementorTemplateRef = null,
        public readonly ?string $seoProfileRef = null,
    ) {}

    public function slot(string $key): ?SlotDefinition
    {
        foreach ($this->slots as $slot) {
            if ($slot->key === $key) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @return array<int, SlotDefinition>
     */
    public function proofSlots(): array
    {
        return array_values(array_filter($this->slots, fn (SlotDefinition $s) => $s->role === SlotRole::Proof));
    }

    /**
     * Parse a WireframeKit's slot_schema. Only `slots` drives the render/publish
     * path; the kit-level metadata (`name`, `page_type`) is descriptive and may be
     * absent on a real persisted kit (e.g. a simpler `{slot: constraints}` schema),
     * so its absence degrades gracefully rather than fatalling the push.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $slots = [];
        foreach (($data['slots'] ?? []) as $slot) {
            if (is_array($slot)) {
                $slots[] = SlotDefinition::fromArray($slot);
            }
        }

        return new self(
            name: (string) ($data['name'] ?? ''),
            version: (int) ($data['version'] ?? 1),
            pageType: PageType::tryFrom((string) ($data['page_type'] ?? '')),
            slots: $slots,
            elementorTemplateRef: isset($data['elementor_template_ref']) ? (string) $data['elementor_template_ref'] : null,
            seoProfileRef: isset($data['seo_profile_ref']) ? (string) $data['seo_profile_ref'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'page_type' => $this->pageType?->value,
            'elementor_template_ref' => $this->elementorTemplateRef,
            'seo_profile_ref' => $this->seoProfileRef,
            'slots' => array_map(fn (SlotDefinition $s) => $s->toArray(), $this->slots),
        ];
    }
}
