<?php

namespace App\ContentEngine\Drafting;

/**
 * Native SEO emitted by the drafter: title tag, meta description, the Open
 * Graph / Twitter card pair, and the URL slug. Generated alongside the body so
 * the metadata matches the drafted angle rather than being bolted on later.
 */
final class Seo
{
    public function __construct(
        public readonly string $title,
        public readonly string $metaDescription,
        public readonly string $slug,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly ?string $twitterTitle = null,
        public readonly ?string $twitterDescription = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $title = (string) ($data['title'] ?? '');
        $meta = (string) ($data['meta_description'] ?? '');

        return new self(
            title: $title,
            metaDescription: $meta,
            slug: (string) ($data['slug'] ?? ''),
            ogTitle: isset($data['og_title']) ? (string) $data['og_title'] : ($data['og']['title'] ?? null),
            ogDescription: isset($data['og_description']) ? (string) $data['og_description'] : ($data['og']['description'] ?? null),
            twitterTitle: isset($data['twitter_title']) ? (string) $data['twitter_title'] : ($data['twitter']['title'] ?? null),
            twitterDescription: isset($data['twitter_description']) ? (string) $data['twitter_description'] : ($data['twitter']['description'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'meta_description' => $this->metaDescription,
            'slug' => $this->slug,
            'og' => [
                'title' => $this->ogTitle ?? $this->title,
                'description' => $this->ogDescription ?? $this->metaDescription,
            ],
            'twitter' => [
                'title' => $this->twitterTitle ?? $this->ogTitle ?? $this->title,
                'description' => $this->twitterDescription ?? $this->ogDescription ?? $this->metaDescription,
            ],
        ];
    }
}
