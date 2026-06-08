<?php

namespace App\ContentEngine\Drafting;

/**
 * The SPEC for an image slot — never a rendered asset. §6b emits the generation
 * prompt and SEO metadata; the real-vs-FLUX render and R2 binding happen later
 * (§2). Carrying the slot key lets the renderer place the result.
 */
final class ImageSpec
{
    public function __construct(
        public readonly string $slot,
        public readonly string $prompt,
        public readonly string $seoFilename,
        public readonly string $alt,
        public readonly ?string $title = null,
        public readonly ?string $caption = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $slot, array $data): self
    {
        return new self(
            slot: $slot,
            prompt: (string) ($data['prompt'] ?? ''),
            seoFilename: (string) ($data['seo_filename'] ?? ''),
            alt: (string) ($data['alt'] ?? ''),
            title: isset($data['title']) ? (string) $data['title'] : null,
            caption: isset($data['caption']) ? (string) $data['caption'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'prompt' => $this->prompt,
            'seo_filename' => $this->seoFilename,
            'alt' => $this->alt,
            'title' => $this->title,
            'caption' => $this->caption,
        ];
    }
}
