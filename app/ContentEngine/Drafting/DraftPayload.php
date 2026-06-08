<?php

namespace App\ContentEngine\Drafting;

/**
 * The parsed drafter output. A page draft fills kit slots; a post draft fills a
 * body. Both carry native SEO, image SPECS (never rendered assets), the business
 * assertions the model claims to have made (for verification), the sources it
 * cited, and any local towns it wove in.
 */
final class DraftPayload
{
    /**
     * @param  array<string, mixed>|null  $slots  kit-slot values (page kind)
     * @param  list<ImageSpec>  $images
     * @param  list<AssertedClaim>  $assertedClaims
     * @param  list<array{name: string, url: string|null}>  $citedSources
     * @param  list<string>  $towns
     */
    public function __construct(
        public readonly ?array $slots,
        public readonly ?string $body,
        public readonly Seo $seo,
        public readonly array $images,
        public readonly array $assertedClaims,
        public readonly array $citedSources,
        public readonly array $towns = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $images = [];
        foreach (($data['images'] ?? []) as $image) {
            if (is_array($image)) {
                $slot = (string) ($image['slot'] ?? '');
                $images[] = ImageSpec::fromArray($slot, $image);
            }
        }

        $asserted = [];
        foreach (($data['claims_used'] ?? []) as $claim) {
            if (is_array($claim)) {
                $asserted[] = AssertedClaim::fromArray($claim);
            }
        }

        $sources = [];
        foreach (($data['sources_cited'] ?? []) as $source) {
            if (is_array($source) && isset($source['name'])) {
                $sources[] = [
                    'name' => (string) $source['name'],
                    'url' => isset($source['url']) ? (string) $source['url'] : null,
                ];
            }
        }

        $towns = [];
        foreach (($data['towns'] ?? []) as $town) {
            $towns[] = (string) $town;
        }

        return new self(
            slots: isset($data['slots']) && is_array($data['slots']) ? $data['slots'] : null,
            body: isset($data['body']) ? (string) $data['body'] : null,
            seo: Seo::fromArray(is_array($data['seo'] ?? null) ? $data['seo'] : []),
            images: $images,
            assertedClaims: $asserted,
            citedSources: $sources,
            towns: $towns,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function imageSpecsArray(): array
    {
        return array_map(fn (ImageSpec $s) => $s->toArray(), $this->images);
    }
}
