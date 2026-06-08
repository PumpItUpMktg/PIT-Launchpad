<?php

namespace App\ContentEngine\Drafting;

/**
 * A Source-pool item (news/competitor context, summarized and copyright-safe).
 * Sources are attributed by name; their content may inform framing but may
 * NEVER become a business claim. A link is emitted only when a clean canonical
 * URL resolves — Google News redirect tokens collapse to name-only, no link.
 */
final class SourceRef
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $summary = null,
        public readonly ?string $url = null,
    ) {}

    /**
     * Whether $url is a canonical, citable destination. Google News (and other
     * aggregator redirect) URLs are opaque redirect tokens, not the publisher's
     * page, so they are never linked — the source is attributed by name only.
     */
    public static function urlIsCitable(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::REDIRECT_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return false;
            }
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private const REDIRECT_HOSTS = [
        'news.google.com',
        'google.com/url',
        'googlenews',
        'feedproxy.google.com',
    ];

    /**
     * The URL safe to render as a link, or null when only name-attribution is
     * permitted.
     */
    public function citableUrl(): ?string
    {
        return self::urlIsCitable($this->url) ? $this->url : null;
    }

    /**
     * @return array{name: string, url: string|null}
     */
    public function attribution(): array
    {
        return ['name' => $this->name, 'url' => $this->citableUrl()];
    }

    public function promptLine(): string
    {
        $line = "- {$this->name}";
        if ($this->summary !== null && $this->summary !== '') {
            $line .= ": {$this->summary}";
        }

        return $line;
    }
}
