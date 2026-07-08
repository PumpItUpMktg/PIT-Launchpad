<?php

namespace App\Publishing\Blocks;

use App\Publishing\Legal\LegalTemplates;

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
            // Emergency call-now uses the dedicated after-hours line when set, else the main number.
            $children[] = $this->b->paragraph(
                'Or call now: <a href="'.$this->attr($ctx->emergencyCallTel()).'">'.$this->text($ctx->emergencyCallDisplay()).'</a>',
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
    public function credibilityStrip(string $lead, array $badges, bool $preview = false): string
    {
        $badges = array_values(array_filter(array_map('trim', $badges), fn (string $b): bool => $b !== ''));
        $placeholder = false;
        if ($badges === []) {
            if (! $preview) {
                return '';
            }
            // Preview only: show the layout with clearly-marked example badges + what activates it.
            $badges = ['Licensed & insured', 'Certified technicians', 'Satisfaction guaranteed'];
            $placeholder = true;
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

        $children = [];
        if ($placeholder) {
            $children[] = $this->placeholderNote('activates when you add licenses, certifications, or ratings');
        }
        $children[] = $this->b->columns($cols, ['className' => 'lp-cred-row']);

        return $this->b->group($children, ['align' => 'full', 'backgroundColor' => 'surface', 'className' => $this->sectionClass('lp-credibility', $placeholder)]);
    }

    /**
     * Certifications / trust row: a row of REAL credentials — each a badge (its uploaded logo, else the
     * text label + optional number). Per-item: only the credentials captured render, never padded. Order
     * is the tenant's captured order (their audience choice). Data-gated: hidden with none. Reusable —
     * home uses it near the top; About / Why-Choose-Us can too. NEVER fabricates a credential.
     *
     * @param  list<array{label?: string, number?: string, logo_url?: string}>  $certs
     */
    public function certificationsRow(array $certs, bool $preview = false): string
    {
        $certs = array_values(array_filter(
            $certs,
            fn (array $c): bool => trim((string) ($c['label'] ?? '')) !== '' || trim((string) ($c['logo_url'] ?? '')) !== '',
        ));

        $placeholder = false;
        if ($certs === []) {
            if (! $preview) {
                return '';
            }
            $certs = [['label' => 'Licensed & Insured'], ['label' => 'NJ Master Plumber', 'number' => '#1234'], ['label' => 'BBB A+ Rated']];
            $placeholder = true;
        }

        $cols = array_map(function (array $c): string {
            $logo = trim((string) ($c['logo_url'] ?? ''));
            $label = trim((string) ($c['label'] ?? ''));
            if ($logo !== '') {
                return $this->b->column([$this->b->image($logo, $label !== '' ? $label : 'Certification', ['className' => 'lp-cert-logo'])]);
            }
            $number = trim((string) ($c['number'] ?? ''));
            $body = '<strong>'.$this->text($label).'</strong>'.($number !== '' ? '<br><span class="lp-cert-num">'.$this->text($number).'</span>' : '');

            return $this->b->column([$this->b->group([$this->b->paragraph($body)], ['className' => 'lp-cert'])]);
        }, $certs);

        $children = [];
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add certifications');
        }
        $children[] = $this->b->columns($cols, ['className' => 'lp-certs-row']);

        return $this->b->group($children, ['align' => 'full', 'backgroundColor' => 'surface', 'className' => $this->sectionClass('lp-certs', $placeholder)]);
    }

    /**
     * Guarantee band: the tenant's guarantee/warranty as a standout risk-reversal PROMISE — an accent
     * band with a shield mark, the guarantee name (headline) + its description. Rendered verbatim (a
     * fabricated guarantee is false advertising). Data-gated: hidden without one. Reusable across pages.
     */
    public function guaranteeBand(string $name, string $description, bool $preview = false): string
    {
        $name = trim($name);
        $description = trim($description);

        $placeholder = false;
        if ($name === '') {
            if (! $preview) {
                return '';
            }
            $name = 'Your guarantee';
            $description = 'Add a guarantee or warranty — it reads here as a standout promise that reverses the risk.';
            $placeholder = true;
        }

        $card = [$this->icon('shield'), $this->b->heading(3, $name, ['textColor' => 'on-accent'])];
        if ($description !== '') {
            $card[] = $this->b->paragraph($this->text($description), ['textColor' => 'on-accent']);
        }

        $children = [];
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add a guarantee', onDark: true);
        }
        $children[] = $this->b->group($card, ['className' => 'lp-guarantee-card']);

        return $this->b->group($children, ['align' => 'full', 'backgroundColor' => 'accent', 'textColor' => 'on-accent', 'className' => $this->sectionClass('lp-guarantee', $placeholder)]);
    }

    /**
     * Why Choose Us: a dark band of differentiators (icon + title + line). Data-gated on real
     * differentiators (from the site narrative) — falls back to nothing when none are captured.
     *
     * @param  list<array{title?: string, description?: string}>  $items
     */
    public function whyChooseUs(string $eyebrow, string $heading, array $items, bool $preview = false): string
    {
        $items = array_values(array_filter($items, fn (array $i): bool => trim((string) ($i['title'] ?? '')) !== ''));
        $placeholder = false;
        if ($items === []) {
            if (! $preview) {
                return '';
            }
            // Preview only: example differentiators so the operator sees the band, marked as example.
            $items = [
                ['title' => 'Upfront pricing', 'description' => 'Know the number before we start — no surprises.'],
                ['title' => 'Experienced crew', 'description' => 'Trained technicians who do it right the first time.'],
                ['title' => 'Work guaranteed', 'description' => 'We stand behind every job we take on.'],
            ];
            $placeholder = true;
        }

        $cols = array_map(function (array $i): string {
            $children = [$this->icon('spark'), $this->b->heading(4, (string) $i['title'], ['textColor' => 'base'])];
            if (trim((string) ($i['description'] ?? '')) !== '') {
                $children[] = $this->b->paragraph((string) $i['description'], ['textColor' => 'base']);
            }

            return $this->b->column([$this->b->group($children, ['className' => 'lp-why-item'])]);
        }, $items);

        $children = [$this->sectionHead($eyebrow, $heading, onDark: true)];
        if ($placeholder) {
            $children[] = $this->placeholderNote('activates when you add what sets you apart', onDark: true);
        }
        $children[] = $this->b->columns($cols, ['className' => 'lp-why-grid']);

        return $this->b->group($children, ['align' => 'full', 'backgroundColor' => 'primary', 'textColor' => 'base', 'className' => $this->sectionClass('lp-why', $placeholder)]);
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

        // Surface band — keeps the light sections alternating surface→base between the dark anchors
        // (hero / why / cta) so no two white sections sit adjacent. Part of the orderly bg rhythm.
        return $this->b->group([
            $this->sectionHead($eyebrow, $heading, center: true),
            $this->b->columns($cols, ['className' => 'lp-steps']),
        ], ['align' => 'full', 'backgroundColor' => 'surface', 'className' => 'lp-process']);
    }

    /**
     * Testimonials: a three-up of client quotes. Data-gated — renders only when real, substantiated
     * reviews exist; never fabricates a quote or a star rating.
     *
     * @param  list<array{quote: string, author?: string, role?: string, stars?: int}>  $quotes
     */
    public function testimonials(string $eyebrow, string $heading, array $quotes, bool $preview = false): string
    {
        $quotes = array_values(array_filter($quotes, fn (array $q): bool => trim($q['quote']) !== ''));
        $placeholder = false;
        if ($quotes === []) {
            if (! $preview) {
                return '';
            }
            // Preview only: example reviews (clearly labeled) so the operator sees the section shape.
            $quotes = [
                ['quote' => 'They diagnosed the problem fast and fixed it right — exactly what they promised.', 'author' => 'Your Google reviews', 'role' => 'appear here', 'stars' => 5],
                ['quote' => 'Professional, on time, and fair on price. I’d call them again in a heartbeat.', 'author' => 'Your Google reviews', 'role' => 'appear here', 'stars' => 5],
            ];
            $placeholder = true;
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

        $children = [$this->sectionHead($eyebrow, $heading, center: true)];
        if ($placeholder) {
            $children[] = $this->placeholderNote('activates when you add reviews');
        }
        $children[] = $this->b->columns($cols, ['className' => 'lp-testimonials-grid']);

        return $this->b->group($children, ['align' => 'full', 'backgroundColor' => 'surface', 'className' => $this->sectionClass('lp-testimonials', $placeholder)]);
    }

    /**
     * Service Areas: a 50/50 block — the interactive map on one side, the MAJOR CITIES from each served
     * county on the other. The county names live in the grouped subheads (so they carry the SEO), so
     * there's no separate county line. The map geometry rides the meta-blob's `service_area_map` (drawn
     * by the theme); the grouped city text is the real, crawlable content (SEO / a11y / no-JS). Data-
     * gated: hidden with no coverage (a labeled example stands in for preview). Geo lives only here +
     * on location pages.
     *
     * @param  list<string>  $counties  named counties served (gates the section; the names show as the group subheads)
     * @param  list<array{county: string, cities: list<array{label: string, url: string}>}>  $byCounty  major cities grouped by county, largest-first; a non-empty url is a REAL town page
     */
    public function serviceAreas(string $eyebrow, string $heading, array $counties, array $byCounty, bool $preview = false, bool $mapAvailable = false): string
    {
        $counties = array_values(array_filter(array_map('trim', $counties), fn (string $c): bool => $c !== ''));

        $placeholder = false;
        if ($counties === [] && $byCounty === []) {
            if (! $preview) {
                return '';
            }
            // Preview only: example territory so the operator sees the section + what to fill in.
            $counties = ['Your county', 'Nearby county'];
            $byCounty = [
                ['county' => 'Your county', 'cities' => [['label' => 'Your city', 'url' => ''], ['label' => 'Nearby town', 'url' => '']]],
                ['county' => 'Nearby county', 'cities' => [['label' => 'Another town', 'url' => ''], ['label' => 'Surrounding area', 'url' => '']]],
            ];
            $placeholder = true;
        }

        $children = [$this->sectionHead($eyebrow, $heading)];
        if ($placeholder) {
            $children[] = $this->placeholderNote('add your service areas to activate this section');
        }

        // The major-cities-by-county column (real geometry only draws the map; text always renders).
        $cities = $this->areaCitiesColumn($byCounty);
        $withMap = $mapAvailable && ! $placeholder;

        if ($withMap && $cities !== '') {
            // 50/50: the map beside the cities. The grouped cities carry the county names (SEO), so no
            // separate county line is needed beneath.
            $children[] = $this->b->columns([
                $this->b->column([$this->areaMapMount()]),
                $this->b->column([$cities]),
            ], ['className' => 'lp-areas-split']);
        } elseif ($cities !== '') {
            $children[] = $cities;
        }

        $classes = $this->sectionClass('lp-areas', $placeholder).($withMap ? ' lp-areas--map' : '');

        return $this->b->group($children, ['align' => 'full', 'className' => $classes]);
    }

    /**
     * Our Story: the brand narrative as readable prose — a section head + the story paragraphs. The
     * caller passes already-cleaned plain-text paragraphs (drafter HTML is stripped upstream). Data-
     * gated: hidden without a story (preview → a labeled example paragraph). The About page's spine.
     *
     * @param  list<string>  $paragraphs
     */
    public function story(string $eyebrow, string $heading, array $paragraphs, bool $preview = false): string
    {
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn (string $p): bool => $p !== ''));
        $placeholder = false;
        if ($paragraphs === []) {
            if (! $preview) {
                return '';
            }
            $paragraphs = ['Tell your story — how you started, who you serve, and what you stand for. It reads here as the narrative that earns a visitor\'s trust.'];
            $placeholder = true;
        }

        $children = [$this->sectionHead($eyebrow, $heading)];
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add your story');
        }
        foreach ($paragraphs as $p) {
            $children[] = $this->b->paragraph($this->text($p), ['className' => 'lp-prose-p']);
        }

        return $this->b->group($children, ['align' => 'full', 'className' => $this->sectionClass('lp-story', $placeholder)]);
    }

    /**
     * Mission statement band: the tenant's mission as ONE standout, centered statement (an eyebrow + the
     * line). Verbatim — never invented. Data-gated: hidden without one (preview → a labeled example).
     */
    public function statementBand(string $statement, bool $preview = false): string
    {
        $statement = trim($statement);
        $placeholder = false;
        if ($statement === '') {
            if (! $preview) {
                return '';
            }
            $statement = 'Add your mission — the one sentence that says why you do this work. It reads here as a standout statement.';
            $placeholder = true;
        }

        $children = [];
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add your mission');
        }
        $children[] = $this->b->paragraph('Our mission', ['textColor' => 'accent', 'fontSize' => 'small', 'className' => 'lp-eyebrow']);
        $children[] = $this->b->paragraph($this->text($statement), ['className' => 'lp-statement-text']);

        return $this->b->group($children, ['align' => 'full', 'backgroundColor' => 'surface', 'className' => $this->sectionClass('lp-statement', $placeholder)]);
    }

    /**
     * Values grid: the tenant's captured values (icon + title + line) on a light band. Distinct from
     * Why-Choose-Us (differentiators, dark): values are who the brand IS, not why it wins. Data-gated on
     * real values — hidden when none (preview → labeled example values). Never invents a value.
     *
     * @param  list<array{title?: string, description?: string}>  $items
     */
    public function valuesGrid(string $eyebrow, string $heading, array $items, bool $preview = false): string
    {
        $items = array_values(array_filter($items, fn (array $i): bool => trim((string) ($i['title'] ?? '')) !== ''));
        $placeholder = false;
        if ($items === []) {
            if (! $preview) {
                return '';
            }
            $items = [
                ['title' => 'Integrity', 'description' => 'We do what we say — every time.'],
                ['title' => 'Craftsmanship', 'description' => 'The job is done right, not just done.'],
                ['title' => 'Respect', 'description' => 'Your home and your time are treated with care.'],
            ];
            $placeholder = true;
        }

        $cols = array_map(function (array $i): string {
            $children = [$this->icon('spark'), $this->b->heading(4, (string) $i['title'])];
            if (trim((string) ($i['description'] ?? '')) !== '') {
                $children[] = $this->b->paragraph((string) $i['description'], ['textColor' => 'muted']);
            }

            return $this->b->column([$this->b->group($children, ['className' => 'lp-value-item'])]);
        }, $items);

        $children = [$this->sectionHead($eyebrow, $heading)];
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add your values');
        }
        $children[] = $this->b->columns($cols, ['className' => 'lp-values-grid']);

        // Base (light) band — the About rhythm alternates white → surface → white so no two flat
        // sections sit adjacent (mission + credibility take the surface bands around it).
        return $this->b->group($children, ['align' => 'full', 'className' => $this->sectionClass('lp-values', $placeholder)]);
    }

    /**
     * Team grid: the real people behind the brand — one card each (photo when captured, else an initials
     * avatar) + name + role + a short bio. Data-gated on captured team members (preview → a labeled
     * example card). Never invents a person. Photos ride as R2 image URLs when present.
     *
     * @param  list<array{name?: string, role?: string, bio?: string, photo_url?: string}>  $members
     */
    public function teamGrid(string $eyebrow, string $heading, array $members, bool $preview = false): string
    {
        $members = array_values(array_filter($members, fn (array $m): bool => trim((string) ($m['name'] ?? '')) !== ''));
        $placeholder = false;
        if ($members === []) {
            if (! $preview) {
                return '';
            }
            $members = [['name' => 'Your team', 'role' => 'The people behind the work', 'bio' => 'Add your team — names, roles, and a line each. Real faces build more trust than stock photos.']];
            $placeholder = true;
        }

        $cols = array_map(function (array $m): string {
            $name = trim((string) ($m['name'] ?? ''));
            $children = [$this->avatar($name, trim((string) ($m['photo_url'] ?? ''))), $this->b->heading(4, $name)];
            $role = trim((string) ($m['role'] ?? ''));
            if ($role !== '') {
                $children[] = $this->b->paragraph($this->text($role), ['textColor' => 'accent', 'fontSize' => 'small', 'className' => 'lp-team-role']);
            }
            $bio = trim((string) ($m['bio'] ?? ''));
            if ($bio !== '') {
                $children[] = $this->b->paragraph($this->text($bio), ['textColor' => 'muted']);
            }

            return $this->b->column([$this->b->group($children, ['backgroundColor' => 'surface', 'className' => 'lp-team-item'])]);
        }, $members);

        $children = [$this->sectionHead($eyebrow, $heading)];
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add your team');
        }
        $children[] = $this->b->columns($cols, ['className' => 'lp-team-grid']);

        return $this->b->group($children, ['align' => 'full', 'className' => $this->sectionClass('lp-team', $placeholder)]);
    }

    /**
     * FAQ accordion: the drafted question/answer pairs as a native <details>/<summary> accordion (no
     * JS — kses-safe, unlike inline SVG). Uses the plugin's canonical .lp-faq / .lp-faq__q / .lp-faq__a
     * class contract so both render paths style identically. Data-gated on real Q&A (preview → a
     * labeled example pair). The plugin emits the FAQPage schema from the same slot payload.
     *
     * @param  list<array{question?: string, answer?: string}>  $items
     */
    public function faqAccordion(string $eyebrow, string $heading, string $intro, array $items, bool $preview = false): string
    {
        $items = array_values(array_filter(
            $items,
            fn (array $i): bool => trim((string) ($i['question'] ?? '')) !== '' && trim((string) ($i['answer'] ?? '')) !== '',
        ));

        $placeholder = false;
        if ($items === []) {
            if (! $preview) {
                return '';
            }
            $items = [
                ['question' => 'How soon can you come out?', 'answer' => 'Add your FAQs — the real questions your customers ask, with honest answers. They read here as an expandable accordion.'],
                ['question' => 'Do you offer free estimates?', 'answer' => 'This example shows the layout; your captured question-and-answer pairs replace it.'],
            ];
            $placeholder = true;
        }

        $rows = '';
        foreach ($items as $item) {
            $rows .= '<details class="lp-faq"><summary class="lp-faq__q">'.$this->text((string) $item['question']).'</summary>'
                .'<div class="lp-faq__a">'.$this->text((string) $item['answer']).'</div></details>';
        }
        $accordion = "<!-- wp:html -->\n".'<div class="lp-faq-list">'.$rows.'</div>'."\n<!-- /wp:html -->";

        $children = [$this->sectionHead($eyebrow, $heading, center: true)];
        if (trim($intro) !== '') {
            $children[] = $this->b->paragraph($this->text($intro), ['textColor' => 'muted', 'className' => 'lp-faq-intro']);
        }
        if ($placeholder) {
            $children[] = $this->placeholderNote('appears when you add your FAQs');
        }
        $children[] = $accordion;

        return $this->b->group($children, ['align' => 'full', 'className' => $this->sectionClass('lp-faqs', $placeholder)]);
    }

    /**
     * Legal document: a plain, readable single-column render of a template-driven legal page (Privacy /
     * Terms) — a page title, an effective date, and headed sections of prose. NOT AI-generated and never
     * data-gated: the {@see LegalTemplates} content is honest boilerplate filled
     * with real tenant data, so it always renders (no marketing hero / CTA — a legal page is not a sell).
     *
     * @param  list<array{heading?: string, paragraphs?: list<string>}>  $sections
     */
    public function legalDocument(string $title, string $effectiveDate, array $sections): string
    {
        $children = [$this->b->heading(1, $title !== '' ? $title : 'Legal', ['className' => 'lp-legal-title'])];

        if (trim($effectiveDate) !== '') {
            $children[] = $this->b->paragraph('Effective date: '.$this->text($effectiveDate), ['textColor' => 'muted', 'className' => 'lp-legal-eff']);
        }

        foreach ($sections as $section) {
            $heading = trim((string) ($section['heading'] ?? ''));
            if ($heading !== '') {
                $children[] = $this->b->heading(3, $heading, ['className' => 'lp-legal-h']);
            }
            foreach (($section['paragraphs'] ?? []) as $paragraph) {
                if (trim((string) $paragraph) !== '') {
                    $children[] = $this->b->paragraph($this->text((string) $paragraph), ['textColor' => 'muted']);
                }
            }
        }

        return $this->b->group($children, ['align' => 'full', 'className' => 'lp-legal']);
    }

    /**
     * A team member's avatar: their real photo when captured, else an initials chip (a classed span the
     * theme styles — kses-safe, unlike inline SVG). Never a fabricated headshot.
     */
    private function avatar(string $name, string $photoUrl): string
    {
        if ($photoUrl !== '') {
            return $this->b->image($photoUrl, $name !== '' ? $name : 'Team member', ['className' => 'lp-team-photo']);
        }

        return "<!-- wp:html -->\n".'<span class="lp-team-avatar" aria-hidden="true">'.$this->text($this->initials($name)).'</span>'."\n<!-- /wp:html -->";
    }

    /** Up to two uppercase initials from a name (falls back to a star when a name has no letters). */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part !== '' && mb_strlen($letters) < 2) {
                $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return $letters !== '' ? $letters : '★';
    }

    /**
     * The "major cities from each county" column: one block per county — the county name + its largest
     * towns on a middot-separated line (a town with a real location page links; never an invented URL).
     * Returns '' when there's nothing to show.
     *
     * @param  list<array{county: string, cities: list<array{label: string, url: string}>}>  $byCounty
     */
    private function areaCitiesColumn(array $byCounty): string
    {
        $blocks = [];
        foreach ($byCounty as $group) {
            $county = trim($group['county']);
            if ($county === '' || $group['cities'] === []) {
                continue;
            }

            $parts = [];
            foreach ($group['cities'] as $city) {
                $label = trim($city['label']);
                if ($label === '') {
                    continue;
                }
                $url = trim($city['url']);
                $parts[] = $url !== '' ? '<a href="'.$this->attr($url).'">'.$this->text($label).'</a>' : $this->text($label);
            }
            if ($parts === []) {
                continue;
            }

            $blocks[] = $this->b->group([
                $this->b->heading(5, $county, ['className' => 'lp-areas-county']),
                $this->b->paragraph(implode(' · ', $parts), ['className' => 'lp-areas-towns', 'textColor' => 'muted']),
            ], ['className' => 'lp-areas-countyblock']);
        }

        return $blocks === [] ? '' : $this->b->group($blocks, ['className' => 'lp-areas-cities']);
    }

    /**
     * The interactive service-area map's mount point. Empty on the server — the theme's Leaflet init
     * draws the served-county polygons + tiered town markers into it from the plugin-printed
     * `service_area_map` geometry (kses would strip embedded geometry from post_content, so it can't
     * ride here). role/aria give it a screen-reader handle; the county + town text beneath is the real
     * crawlable fallback for no-JS and search.
     */
    private function areaMapMount(): string
    {
        return "<!-- wp:html -->\n".'<div class="lp-areas-map" role="img" aria-label="Map of the areas we serve"></div>'."\n<!-- /wp:html -->";
    }

    // ── section internals ──

    /**
     * The preview-only placeholder banner for a data-gated section: an "Example" tag + a plain-language
     * line naming what activates the section. Marks the example content so the operator never mistakes
     * it for real copy — and the `lp-placeholder` section class greys the whole block in the theme.
     * NEVER reaches the live site: a placeholder is built only in preview, and publish omits the
     * section entirely (each builder returns '' when its data is empty and preview is off).
     */
    private function placeholderNote(string $activates, bool $onDark = false): string
    {
        $attrs = ['className' => 'lp-placeholder-note', 'fontSize' => 'small'];
        if ($onDark) {
            $attrs['textColor'] = 'base';
        }

        return $this->b->paragraph('<span class="lp-placeholder-tag">Example</span> '.$this->text($activates), $attrs);
    }

    /** A section's className, with the `lp-placeholder` marker appended when it's a preview placeholder. */
    private function sectionClass(string $base, bool $placeholder): string
    {
        return $placeholder ? $base.' lp-placeholder' : $base;
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
        $children = [$this->icon((new ServiceIcon)->slugFor((string) ($c['title'] ?? '')))];
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

    /**
     * A curated icon as a CLASS (drawn by the theme's CSS), NOT inline SVG — WordPress' kses strips
     * <svg> from post_content on save, so an inline icon renders empty. A classed span survives.
     */
    private function icon(string $slug = ServiceIcon::FALLBACK): string
    {
        return "<!-- wp:html -->\n".'<span class="lp-icon lp-icon--'.$slug.'" aria-hidden="true"></span>'."\n<!-- /wp:html -->";
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
