<?php

namespace App\Publishing\Legal;

/**
 * The tenant facts a {@see LegalTemplates} document is parameterized with — resolved once from §1 and
 * passed in, so the templates stay pure and deterministic. Every field degrades gracefully: a missing
 * contact channel simply drops from the "contact us" sentence, a missing site URL becomes "this website".
 */
final class LegalContext
{
    public function __construct(
        public readonly string $business,
        public readonly ?string $siteUrl = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly string $effectiveDate = '',
    ) {}

    /** The site as prose — its bare host when a URL is known, else a neutral fallback. */
    public function siteLabel(): string
    {
        $url = trim((string) $this->siteUrl);
        if ($url === '') {
            return 'this website';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    /**
     * The "contact us" sentence — lists only the channels actually captured (email / phone), else
     * points to the contact page. Never invents a channel.
     */
    public function contactSentence(string $topic): string
    {
        $channels = [];
        if (trim((string) $this->email) !== '') {
            $channels[] = "email at {$this->email}";
        }
        if (trim((string) $this->phone) !== '') {
            $channels[] = "phone at {$this->phone}";
        }

        if ($channels === []) {
            return "If you have questions about this {$topic}, please reach out through the contact page on this website.";
        }

        return "If you have questions about this {$topic}, you can reach {$this->business} by ".implode(' or ', $channels).'.';
    }
}
