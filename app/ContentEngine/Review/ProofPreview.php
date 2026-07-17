<?php

namespace App\ContentEngine\Review;

use App\Enums\SlotContentType;
use App\Models\Content;
use App\PageBuilder\Schema\SlotDefinition;
use App\Styling\StyleActivator;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The structured proof preview (§4) — the page rendered in the kit's block structure with the brand
 * kit applied: the right sections in order, the real generated copy, brand colors/type. NOT a
 * generic Launchpad view (that would mean approving something unlike what ships); the remaining
 * fidelity gap is the Elementor pixel-nuance the WP templates own (the WP-rendered iframe is the
 * later upgrade — see PagePreviewService).
 *
 * Read-first: the operator scans top-to-bottom like a visitor and approves. Each section carries
 * whether it's editable in place (entity-sourced slots are platform-filled, never edited here) and
 * whether it's an image slot. The SEO strip is the "search appearance" line.
 */
class ProofPreview
{
    /**
     * @return array{brand: array{name: string, logo_url: ?string, primary: string, accent: string, heading_font: string}, sections: list<array<string, mixed>>, seo: array{title: ?string, meta_description: ?string}, permalink: string}
     */
    public function for(Content $page): array
    {
        return [
            'brand' => $this->brand($page),
            'sections' => $this->sections($page),
            'seo' => $this->seo($page),
            'permalink' => '/'.ltrim((string) $page->slug, '/'), // shown, locked
        ];
    }

    /**
     * The brand identity for the preview chrome: the Account's name + logo, but the COLORS + heading
     * font come from the site's ACTIVE theme.json variation (what actually ships) — not the raw Account
     * palette — so the operator proofs the real look.
     *
     * @return array{name: string, logo_url: ?string, primary: string, accent: string, heading_font: string}
     */
    private function brand(Content $page): array
    {
        $account = $page->site?->account;
        $identity = $account !== null
            ? $account->branding()
            : ['name' => '', 'logo_url' => null, 'primary' => '#0B2545', 'accent' => '#5BC0EB'];

        $site = $page->site;
        if ($site !== null) {
            $look = app(StyleActivator::class)->activeLook($site);
            $identity['primary'] = $look['primary'];
            $identity['accent'] = $look['accent'];
            $identity['heading_font'] = $look['heading_font'];
        }

        $identity['heading_font'] ??= 'inherit';

        return $identity;
    }

    /**
     * The kit's slots in order, each resolved against the draft's slot_payload.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(Content $page): array
    {
        $schema = $page->wireframe_kit_id !== null ? $page->wireframeKit?->schema() : null;
        if ($schema === null) {
            return [];
        }

        $payload = is_array($page->slot_payload) ? $page->slot_payload : [];

        $sections = [];
        foreach ($schema->slots as $slot) {
            $value = $payload[$slot->key] ?? null;
            $sections[] = [
                'key' => $slot->key,
                'label' => $slot->label,
                'role' => $slot->role->value,
                'type' => $slot->contentType->value,
                'is_image' => $this->isImage($slot),
                // Edit-in-place is a safety valve for the rare off-base/off-brand block. Entity slots
                // are platform-filled (proof counts, NAP) — shown, never edited here.
                'editable' => ! $slot->source->resolvesAgainstEntities(),
                'value' => $value,
                'empty' => $this->isEmpty($value),
                // Rich-text slots (body copy) hold real HTML — <p>, internal <a> links, entities.
                // Rendered as HTML in the preview ("as a visitor reads it"), sanitized; scalar-only.
                'html' => in_array($slot->contentType, [SlotContentType::RichText, SlotContentType::LongText], true)
                    && is_string($value)
                    ? $this->safeHtml($value)
                    : null,
                // FAQ is a list of {question, answer}; the answer is HTML. Presented as Q/A pairs with
                // the answer rendered (sanitized), not the flat imploded list the generic path shows.
                'faq' => $slot->contentType === SlotContentType::Faq ? $this->faqPairs($value) : null,
            ];
        }

        return $sections;
    }

    /**
     * The FAQ entries with each answer rendered as sanitized HTML.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function faqPairs(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $question = trim((string) ($entry['question'] ?? ''));
            if ($question === '') {
                continue;
            }
            $out[] = ['question' => $question, 'answer' => $this->safeHtml((string) ($entry['answer'] ?? ''))];
        }

        return $out;
    }

    /**
     * Sanitize engine-generated body HTML for the admin preview — an allowlist of the tags the
     * drafter actually emits (paragraphs, internal links, lists, emphasis, headings). Strips scripts,
     * event handlers, and dangerous URL schemes even though the source is our own drafter.
     */
    private function safeHtml(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('b')
            ->allowElement('i')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks();

        return trim((new HtmlSanitizer($config))->sanitize($html));
    }

    private function isImage(SlotDefinition $slot): bool
    {
        return in_array($slot->contentType, [SlotContentType::Image, SlotContentType::Gallery], true);
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return ! is_string($value) || trim($value) === '';
    }

    /**
     * @return array{title: ?string, meta_description: ?string}
     */
    private function seo(Content $page): array
    {
        $seo = is_array($page->meta['seo'] ?? null) ? $page->meta['seo'] : [];

        return [
            'title' => is_string($seo['title'] ?? null) ? $seo['title'] : null,
            'meta_description' => is_string($seo['meta_description'] ?? null) ? $seo['meta_description'] : null,
        ];
    }
}
