<?php

namespace App\Publishing\Blocks;

/**
 * The mockup's page sections translated to CORE-block ARRANGEMENTS (not its CSS — styling lives in
 * theme.json). Each builder returns block markup for one section; a page composer orders them.
 * Sections are style-agnostic (colors are palette slugs), so the three theme.json variations restyle
 * the same markup. Built once here, reused across page types.
 *
 * The mockup sections: split hero (text + AI image, phone/emergency-gated buttons + trust), the
 * services-grid (cards → real child pages, with icons), the proof gallery (honest "add your own
 * photo" placeholders), and the CTA (emergency call-now line).
 */
final class BlockSections
{
    public function __construct(private readonly BlockBuilder $b) {}

    /**
     * The split hero: a primary-background group with two columns — copy + buttons + trust stats on
     * the left, the AI hero image on the right. Buttons and the trust row are emergency-gated.
     *
     * @param  list<array{value: string, label: string}>  $trust
     */
    public function hero(
        string $eyebrow,
        string $headline,
        string $subhead,
        ?string $imageUrl,
        string $imageAlt,
        string $assessmentText,
        string $assessmentUrl,
        array $trust,
        PageContext $ctx,
    ): string {
        $left = [
            $this->b->paragraph($eyebrow, ['textColor' => 'accent', 'fontSize' => 'small', 'className' => 'lp-eyebrow']),
            $this->b->heading(1, $headline, ['textColor' => 'base']),
            $this->b->paragraph($subhead, ['textColor' => 'base']),
            $this->heroButtons($assessmentText, $assessmentUrl, $ctx),
            $this->trustRow($trust),
        ];

        $right = [];
        if ($imageUrl !== null && trim($imageUrl) !== '') {
            $right[] = $this->b->image($imageUrl, $imageAlt !== '' ? $imageAlt : $headline);
        }

        $columns = [$this->b->column($left, ['width' => '55%'])];
        if ($right !== []) {
            $columns[] = $this->b->column($right, ['width' => '45%']);
        }

        return $this->b->group([
            $this->b->columns($columns),
        ], ['backgroundColor' => 'primary', 'textColor' => 'base', 'className' => 'lp-hero']);
    }

    /**
     * The services grid: a section head + a 3-up of cards. Each card is a surface group with an icon,
     * the service name, a blurb, and a "Learn more" link to the REAL child page (internal-link safe —
     * cards only exist for pages the caller resolved, never an invented URL).
     *
     * @param  list<array{title?: string, blurb?: string, url?: string}>  $cards
     */
    public function servicesGrid(string $eyebrow, string $heading, array $cards): string
    {
        $cards = array_values(array_filter($cards, fn (array $c): bool => trim($c['title'] ?? '') !== ''));
        if ($cards === []) {
            return '';
        }

        $columns = array_map(fn (array $c): string => $this->b->column([$this->serviceCard($c)]), $cards);

        return $this->b->group([
            $this->sectionHead($eyebrow, $heading),
            $this->b->columns($columns, ['className' => 'lp-services-grid']),
        ], ['className' => 'lp-services']);
    }

    /**
     * The proof gallery: honest photo slots. The client's real photos beat any stock image, so unfilled
     * slots render as an explicit "add your own photo" placeholder — never a fabricated image. A
     * provided (AI/uploaded) image fills a slot; the rest stay placeholders.
     *
     * @param  list<string>  $imageUrls  already-resolved image urls for filled slots (may be empty)
     */
    public function proofGallery(string $eyebrow, string $heading, array $imageUrls = [], int $slots = 3): string
    {
        $imageUrls = array_values(array_filter(array_map('trim', $imageUrls), fn (string $u): bool => $u !== ''));

        $cells = [];
        for ($i = 0; $i < $slots; $i++) {
            $url = $imageUrls[$i] ?? null;
            $cells[] = $this->b->column([
                $url !== null
                    ? $this->b->image($url, 'Our work')
                    : $this->photoPlaceholder(),
            ]);
        }

        return $this->b->group([
            $this->sectionHead($eyebrow, $heading),
            $this->b->columns($cells, ['className' => 'lp-proof-grid']),
        ], ['className' => 'lp-proof']);
    }

    /**
     * The closing CTA: a primary-background rounded group with a heading, a line, an emergency-only
     * "call now" link, and the primary action button.
     */
    public function cta(string $heading, string $body, string $actionText, string $actionUrl, PageContext $ctx): string
    {
        $children = [
            $this->b->heading(2, $heading, ['textColor' => 'base']),
            $this->b->paragraph($body, ['textColor' => 'base']),
        ];

        if ($ctx->emergency && $ctx->hasPhone()) {
            $children[] = $this->b->paragraph(
                'Or call now: <a href="'.$this->attr($ctx->phoneTel).'">'.$this->text($ctx->phoneDisplay).'</a>',
                ['textColor' => 'base', 'className' => 'lp-callnow'],
            );
        }

        $children[] = $this->b->buttons([
            ['text' => $actionText, 'url' => $actionUrl !== '' ? $actionUrl : '#contact', 'attrs' => ['backgroundColor' => 'accent', 'textColor' => 'on-accent']],
        ]);

        return $this->b->group($children, ['backgroundColor' => 'primary', 'textColor' => 'base', 'className' => 'lp-cta']);
    }

    // ── section internals ──

    private function heroButtons(string $assessmentText, string $assessmentUrl, PageContext $ctx): string
    {
        $assessmentUrl = $assessmentUrl !== '' ? $assessmentUrl : '#contact';
        $call = $ctx->hasPhone()
            ? ['text' => 'Call '.$this->text($ctx->phoneDisplay), 'url' => (string) $ctx->phoneTel]
            : null;
        $assessment = ['text' => $assessmentText !== '' ? $assessmentText : 'Get a free assessment', 'url' => $assessmentUrl];

        $primary = ['backgroundColor' => 'accent', 'textColor' => 'on-accent'];
        $secondary = ['className' => 'is-style-outline', 'textColor' => 'base'];

        // Emergency ON → the call leads (primary); OFF → the assessment leads.
        $buttons = [];
        if ($ctx->emergency && $call !== null) {
            $buttons[] = $call + ['attrs' => $primary];
            $buttons[] = $assessment + ['attrs' => $secondary];
        } else {
            $buttons[] = $assessment + ['attrs' => $primary];
            if ($call !== null) {
                $buttons[] = $call + ['attrs' => $secondary];
            }
        }

        return $this->b->buttons($buttons);
    }

    /**
     * @param  list<array{value?: string, label?: string}>  $trust
     */
    private function trustRow(array $trust): string
    {
        $trust = array_values(array_filter($trust, fn (array $t): bool => trim($t['value'] ?? '') !== ''));
        if ($trust === []) {
            return '';
        }

        $columns = array_map(
            fn (array $t): string => $this->b->column([
                $this->b->paragraph('<strong>'.$this->text($t['value']).'</strong><br>'.$this->text($t['label'] ?? ''), ['textColor' => 'base']),
            ]),
            $trust,
        );

        return $this->b->columns($columns, ['className' => 'lp-trust']);
    }

    /**
     * @param  array{title?: string, blurb?: string, url?: string}  $c
     */
    private function serviceCard(array $c): string
    {
        $children = [$this->icon()];
        $children[] = $this->b->heading(3, (string) $c['title']);
        if (trim((string) ($c['blurb'] ?? '')) !== '') {
            $children[] = $this->b->paragraph((string) $c['blurb'], ['textColor' => 'muted']);
        }
        $url = trim((string) ($c['url'] ?? ''));
        if ($url !== '') {
            $children[] = $this->b->paragraph('<a href="'.$this->attr($url).'">Learn more →</a>', ['textColor' => 'accent']);
        }

        return $this->b->group($children, ['backgroundColor' => 'surface', 'className' => 'lp-card']);
    }

    private function photoPlaceholder(): string
    {
        return $this->b->group([
            $this->b->heading(4, 'Add your own photo'),
            $this->b->paragraph('Your crew on site builds more trust than any stock image.', ['textColor' => 'muted', 'fontSize' => 'small']),
        ], ['backgroundColor' => 'surface', 'className' => 'lp-photo-placeholder']);
    }

    private function sectionHead(string $eyebrow, string $heading): string
    {
        return $this->b->group([
            $this->b->paragraph($eyebrow, ['textColor' => 'accent', 'fontSize' => 'small', 'className' => 'lp-eyebrow']),
            $this->b->heading(2, $heading),
        ], ['className' => 'lp-section-head']);
    }

    /** A single generic service icon (inline SVG via core/html — a CORE block, so still portable). */
    private function icon(): string
    {
        $svg = '<span class="lp-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></span>';

        return "<!-- wp:html -->\n{$svg}\n<!-- /wp:html -->";
    }

    private function text(?string $v): string
    {
        return htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
    }

    private function attr(?string $v): string
    {
        return htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
    }
}
