<?php

namespace App\Publishing\Blocks;

/**
 * Composes a page's `post_content` as core Gutenberg block markup from its resolved slots — the
 * Elementor→Gutenberg pivot's replacement for `_elementor_data` assembly. Orders the mockup's
 * {@see BlockSections} per page type; the block theme's active `theme.json` variation styles the
 * result. Pure: slots/images/cards/context in, block markup out — resolution (child pages, phone,
 * emergency) happens upstream so this stays testable and deterministic.
 *
 * This pass composes the HOME page (the mockup's subject: hero → services-grid → proof → CTA). Other
 * page types reuse the same section builders in their own order and land next.
 */
final class BlockPageComposer
{
    public function __construct(private readonly BlockSections $sections) {}

    /**
     * @param  array<string, mixed>  $slots  the page's resolved slot_payload
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot
     * @param  list<array{title: string, blurb: string, url: string}>  $serviceCards  resolved child service pages (real internal links)
     * @param  list<array{value?: string, label?: string}>  $trustStats  substantiated proof stats (never fabricated) for the hero trust row
     */
    public function composeHome(array $slots, array $images, array $serviceCards, PageContext $ctx, array $trustStats = []): string
    {
        $hero = $this->sections->hero(
            eyebrow: $this->str($slots['service_area'] ?? ''),
            headline: $this->str($slots['hero_headline'] ?? ''),
            subhead: $this->str($slots['hero_subhead'] ?? $slots['intro'] ?? ''),
            imageUrl: $this->imageUrl('hero_image', $images),
            imageAlt: $this->imageAlt('hero_image', $images),
            assessmentText: 'Get a free assessment',
            assessmentUrl: '#contact',
            trust: $this->heroTrust($ctx, $trustStats),
            ctx: $ctx,
        );

        $services = $this->sections->servicesGrid(
            eyebrow: 'What we do',
            heading: $this->str($slots['hero_subhead'] ?? '') !== '' ? 'Our services' : 'Our services',
            cards: $serviceCards,
        );

        $proof = $this->sections->proofGallery(
            eyebrow: 'Proof',
            heading: 'Show the work you’re proud of',
            imageUrls: $this->galleryUrls($images),
        );

        $cta = $this->sections->cta(
            heading: 'Ready to get started?',
            body: 'Get in touch for a free, no-obligation assessment.',
            actionText: 'Schedule an assessment',
            actionUrl: '#contact',
            ctx: $ctx,
        );

        return $this->join([$hero, $services, $proof, $cta]);
    }

    /** @param list<string> $blocks */
    private function join(array $blocks): string
    {
        return implode("\n\n", array_values(array_filter($blocks, fn (string $b): bool => trim($b) !== '')));
    }

    /**
     * The hero trust row. Emergency (opted-in) contributes the honest "24/7 · Emergency response"
     * stat; everything else must be substantiated proof passed in — never fabricated here.
     *
     * @param  list<array{value?: string, label?: string}>  $trustStats
     * @return list<array{value: string, label: string}>
     */
    private function heroTrust(PageContext $ctx, array $trustStats): array
    {
        $row = [];
        if ($ctx->emergency && $ctx->hasPhone()) {
            $row[] = ['value' => '24/7', 'label' => 'Emergency response'];
        }
        foreach ($trustStats as $stat) {
            if (trim((string) ($stat['value'] ?? '')) !== '') {
                $row[] = ['value' => (string) $stat['value'], 'label' => (string) ($stat['label'] ?? '')];
            }
        }

        return array_slice($row, 0, 4);
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     * @return list<string>
     */
    private function galleryUrls(array $images): array
    {
        $urls = [];
        foreach (['proof_image', 'gallery'] as $key) {
            $url = $this->imageUrl($key, $images);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function str(mixed $value): string
    {
        return trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     */
    private function imageUrl(string $key, array $images): ?string
    {
        $url = $images[$key]['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     */
    private function imageAlt(string $key, array $images): string
    {
        return (string) ($images[$key]['alt'] ?? '');
    }
}
