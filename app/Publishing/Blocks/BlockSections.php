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
            $this->b->paragraph($eyebrow, ['textColor' => 'base', 'fontSize' => 'small', 'className' => 'lp-eyebrow']),
            $this->b->heading(1, $headline, ['textColor' => 'base']),
            $this->b->paragraph($subhead, ['textColor' => 'base']),
            $this->heroButtons($assessmentText, $assessmentUrl, $ctx),
            $this->trustRow($trust),
        ];

        $right = [];
        if ($imageUrl !== null && trim($imageUrl) !== '') {
            $right[] = $this->b->image($imageUrl, $imageAlt !== '' ? $imageAlt : $headline);
        }

        $columns = [$this->b->column($left, ['width' => '60%'])];
        if ($right !== []) {
            $columns[] = $this->b->column($right, ['width' => '40%']);
        }

        return $this->b->group([
            $this->b->columns($columns),
        ], ['backgroundColor' => 'primary', 'textColor' => 'base', 'align' => 'full', 'className' => 'lp-hero']);
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
        ], ['align' => 'full', 'className' => 'lp-services']);
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
        ], ['align' => 'full', 'className' => 'lp-proof']);
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

        return $this->b->group($children, ['backgroundColor' => 'primary', 'textColor' => 'base', 'align' => 'full', 'className' => 'lp-cta']);
    }

    /**
     * The credibility strip: a slim reassurance band — an optional lead line + a row of substantiated
     * trust badges (licensed, certified, rated). Data-gated: renders nothing when there are no badges,
     * so an unsubstantiated tenant degrades to no strip rather than an empty or fabricated one.
     *
     * @param  list<string>  $badges  substantiated proof labels (already resolved upstream; never invented)
     */
    public function credibilityStrip(string $lead, array $badges): string
    {
        $badges = array_values(array_filter(array_map('trim', $badges), fn (string $b): bool => $b !== ''));
        if ($badges === []) {
            return '';
        }

        $cols = [];
        if (trim($lead) !== '') {
            $cols[] = $this->b->column([
                $this->b->paragraph($this->text($lead), ['textColor' => 'muted', 'className' => 'lp-cred-lead']),
            ]);
        }
        foreach ($badges as $badge) {
            $cols[] = $this->b->column([
                $this->b->paragraph('<span class="lp-check" aria-hidden="true">✓</span> '.$this->text($badge), ['className' => 'lp-cred-item']),
            ]);
        }

        return $this->b->group([
            $this->b->columns($cols, ['className' => 'lp-cred-row']),
        ], ['align' => 'full', 'backgroundColor' => 'surface', 'className' => 'lp-credibility']);
    }

    /**
     * Why Choose Us: a dark band of differentiators (icon + title + line). Data-gated on real
     * differentiators (from the site narrative) — falls back to nothing when none are captured.
     *
     * @param  list<array{title?: string, description?: string}>  $items
     */
    public function whyChooseUs(string $eyebrow, string $heading, array $items): string
    {
        $items = array_values(array_filter($items, fn (array $i): bool => trim((string) ($i['title'] ?? '')) !== ''));
        if ($items === []) {
            return '';
        }

        $cols = array_map(function (array $i): string {
            $children = [$this->icon(), $this->b->heading(4, (string) $i['title'], ['textColor' => 'base'])];
            if (trim((string) ($i['description'] ?? '')) !== '') {
                $children[] = $this->b->paragraph((string) $i['description'], ['textColor' => 'base']);
            }

            return $this->b->column([$this->b->group($children, ['className' => 'lp-why-item'])]);
        }, $items);

        return $this->b->group([
            $this->sectionHead($eyebrow, $heading, onDark: true),
            $this->b->columns($cols, ['className' => 'lp-why-grid']),
        ], ['align' => 'full', 'backgroundColor' => 'primary', 'textColor' => 'base', 'className' => 'lp-why']);
    }

    /**
     * How It Works: a numbered three-step process. Presentational and business-agnostic (no fabricated
     * specifics), so it always renders. Callers may pass their own steps; the default is a safe,
     * universally-true assessment → plan → service arc.
     *
     * @param  list<array{title: string, description: string}>  $steps
     */
    public function howItWorks(string $eyebrow, string $heading, array $steps = []): string
    {
        if ($steps === []) {
            $steps = [
                ['title' => 'Free assessment', 'description' => "Reach out and we'll assess exactly what you need — no obligation."],
                ['title' => 'A plan that fits', 'description' => 'A clear plan built around your situation, timeline, and budget.'],
                ['title' => 'We handle it', 'description' => 'Scheduled, reliable service and a team you can count on.'],
            ];
        }

        $n = 0;
        $cols = array_map(function (array $s) use (&$n): string {
            $n++;

            return $this->b->column([
                $this->b->group([
                    $this->b->paragraph((string) $n, ['className' => 'lp-step-n']),
                    $this->b->heading(4, (string) $s['title']),
                    $this->b->paragraph((string) $s['description'], ['textColor' => 'muted']),
                ], ['className' => 'lp-step']),
            ]);
        }, $steps);

        return $this->b->group([
            $this->sectionHead($eyebrow, $heading, center: true),
            $this->b->columns($cols, ['className' => 'lp-steps']),
        ], ['align' => 'full', 'className' => 'lp-process']);
    }

    /**
     * Testimonials: a three-up of client quotes. Data-gated — renders only when real, substantiated
     * reviews exist; never fabricates a quote or a star rating.
     *
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $quotes
     */
    public function testimonials(string $eyebrow, string $heading, array $quotes): string
    {
        $quotes = array_values(array_filter($quotes, fn (array $q): bool => trim($q['quote']) !== ''));
        if ($quotes === []) {
            return '';
        }

        $cols = array_map(function (array $q): string {
            $children = [];
            $stars = (int) ($q['stars'] ?? 0);
            if ($stars > 0) {
                $children[] = $this->b->paragraph(str_repeat('★', min($stars, 5)), ['textColor' => 'accent', 'className' => 'lp-stars']);
            }
            $children[] = $this->b->paragraph('“'.$this->text($q['quote']).'”', ['className' => 'lp-quote-text']);

            $author = trim((string) ($q['author'] ?? ''));
            $role = trim((string) ($q['role'] ?? ''));
            if ($author !== '' || $role !== '') {
                $who = '<strong>'.$this->text($author !== '' ? $author : $role).'</strong>';
                if ($author !== '' && $role !== '') {
                    $who .= '<br><span class="lp-who-role">'.$this->text($role).'</span>';
                }
                $children[] = $this->b->paragraph($who, ['className' => 'lp-who']);
            }

            return $this->b->column([$this->b->group($children, ['backgroundColor' => 'base', 'className' => 'lp-quote'])]);
        }, $quotes);

        return $this->b->group([
            $this->sectionHead($eyebrow, $heading, center: true),
            $this->b->columns($cols, ['className' => 'lp-testimonials-grid']),
        ], ['align' => 'full', 'backgroundColor' => 'surface', 'className' => 'lp-testimonials']);
    }

    /**
     * Service Areas: the COUNTY level first (a "Serving … counties" lead), then the towns as pill tags
     * ordered largest-first — a readable hierarchy, not a crowded cloud. Data-gated: hidden when a tenant
     * has no coverage captured. Geo lives only here and on location pages, never in silo/service copy.
     *
     * @param  list<string>  $counties  named counties served (broadest first line)
     * @param  list<array{label: string, url: string}>  $cities  towns largest-first; a non-empty url is a REAL town page
     */
    public function serviceAreas(string $eyebrow, string $heading, array $counties, array $cities, ?string $more = null): string
    {
        $counties = array_values(array_filter(array_map('trim', $counties), fn (string $c): bool => $c !== ''));

        // A town with a real location page becomes a link; otherwise a plain pill. Never an invented URL.
        $cityTags = [];
        foreach ($cities as $city) {
            $label = trim($city['label']);
            if ($label === '') {
                continue;
            }
            $url = trim($city['url']);
            $cityTags[] = $url !== ''
                ? '<a href="'.$this->attr($url).'">'.$this->text($label).'</a>'
                : $this->text($label);
        }

        if ($counties === [] && $cityTags === []) {
            return '';
        }

        $children = [$this->sectionHead($eyebrow, $heading)];

        if ($counties !== []) {
            $children[] = $this->b->paragraph(
                'Serving '.$this->naturalList(array_map(fn (string $c): string => $this->text($c), $counties)).'.',
                ['className' => 'lp-areas-counties'],
            );
        }

        if ($cityTags !== []) {
            if ($more !== null && trim($more) !== '') {
                $cityTags[] = $this->text(trim($more));
            }
            $children[] = $this->b->list($cityTags, ['className' => 'lp-area-tags']);
        }

        return $this->b->group($children, ['align' => 'full', 'className' => 'lp-areas']);
    }

    // ── section internals ──

    /**
     * "A" · "A and B" · "A, B, and C" — a natural-language join. Items are already escaped.
     *
     * @param  list<string>  $items
     */
    private function naturalList(array $items): string
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0].' and '.$items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items).', and '.$last;
    }

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

    private function sectionHead(string $eyebrow, string $heading, bool $onDark = false, bool $center = false): string
    {
        $classes = 'lp-section-head'.($center ? ' lp-section-head--center' : '');

        return $this->b->group([
            $this->b->paragraph($eyebrow, ['textColor' => 'accent', 'fontSize' => 'small', 'className' => 'lp-eyebrow']),
            $this->b->heading(2, $heading, $onDark ? ['textColor' => 'base'] : []),
        ], ['className' => $classes]);
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
