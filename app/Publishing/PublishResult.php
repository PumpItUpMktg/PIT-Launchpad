<?php

namespace App\Publishing;

use App\Models\Content;

/**
 * The outcome of a publish attempt. `skipped` protects operator edits (locked /
 * locally edited), `blocked` is a required image that won't render, `failed` is
 * a push error (bounded-retry candidate), `published` is live.
 */
final class PublishResult
{
    private function __construct(
        public readonly string $state,
        public readonly Content $content,
        public readonly string $message,
        public readonly ?int $wpPostId = null,
    ) {}

    public static function published(Content $content, int $wpPostId): self
    {
        return new self('published', $content, 'Published to WordPress.', $wpPostId);
    }

    public static function skipped(Content $content, string $message): self
    {
        return new self('skipped', $content, $message);
    }

    public static function blocked(Content $content, string $message): self
    {
        return new self('blocked', $content, $message);
    }

    public static function failed(Content $content, string $message): self
    {
        return new self('failed', $content, $message);
    }

    public function isPublished(): bool
    {
        return $this->state === 'published';
    }

    public function wasSkipped(): bool
    {
        return $this->state === 'skipped';
    }

    public function isBlocked(): bool
    {
        return $this->state === 'blocked';
    }

    public function hasFailed(): bool
    {
        return $this->state === 'failed';
    }
}
