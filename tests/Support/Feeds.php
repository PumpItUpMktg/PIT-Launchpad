<?php

namespace Tests\Support;

use App\Enums\FeedOrigin;
use App\Models\Source;

/**
 * Shared RSS fixtures + feed builders for the §6a Phase 2 (client feeds) tests.
 * Kept in a class (not loose Pest helpers) so multiple test files can reuse them
 * without colliding on global function names.
 */
class Feeds
{
    public static function directXml(
        string $title = 'A gadget launches today',
        string $link = 'https://techcrunch.com/2026/gadget',
        string $channel = 'TechCrunch',
    ): string {
        return '<?xml version="1.0"?><rss version="2.0"><channel><title>'.$channel.'</title>'
            .'<item><title>'.$title.'</title><link>'.$link.'</link>'
            .'<description>&lt;p&gt;A short snippet describing the development.&lt;/p&gt;</description>'
            .'<pubDate>Mon, 01 Jun 2026 10:00:00 GMT</pubDate></item></channel></rss>';
    }

    /** A google.news article link whose base64 id decodes to a publisher URL (legacy form). */
    public static function gnewsCleanLink(string $publisherUrl): string
    {
        $blob = "\x08\x13\x22".chr(strlen($publisherUrl)).$publisherUrl."\xd2\x01\x00";
        $id = rtrim(strtr(base64_encode($blob), '+/', '-_'), '=');

        return "https://news.google.com/rss/articles/{$id}?oc=5";
    }

    /** A modern opaque google.news article link — base64 decodes to bytes with no URL. */
    public static function gnewsOpaqueLink(): string
    {
        $id = rtrim(strtr(base64_encode("\x08\x13\x22\x10no-url-only-bytes-here"), '+/', '-_'), '=');

        return "https://news.google.com/rss/articles/{$id}?oc=5";
    }

    public static function client(string $url): Source
    {
        return new Source(['url' => $url, 'origin' => FeedOrigin::Client]);
    }
}
