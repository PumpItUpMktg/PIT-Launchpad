<?php

namespace App\Publishing;

/**
 * The outcome of a preview-push. `ready` carries the WP-rendered preview URL the proof editor
 * iframes; `unavailable` means there's no draft to preview yet; `failed` is a push/transport error.
 */
final class PreviewResult
{
    private function __construct(
        public readonly string $state,
        public readonly string $message,
        public readonly ?int $wpPostId = null,
        public readonly ?string $previewUrl = null,
    ) {}

    public static function ready(int $wpPostId, ?string $previewUrl): self
    {
        return new self('ready', 'Preview ready.', $wpPostId, $previewUrl);
    }

    public static function unavailable(string $message): self
    {
        return new self('unavailable', $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }
}
