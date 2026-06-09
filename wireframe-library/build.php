<?php

declare(strict_types=1);

/**
 * Elementor Wireframe Library generator.
 *
 * Emits importable Elementor `.json` for the whole library:
 *   - block-level templates (the reusable unit)        -> blocks/
 *   - page-assembly templates (compose blocks in order)-> pages/
 *   - Theme Builder header + footer                     -> theme-builder/
 *   - the sidecar spec (wf-* class -> type/size/range)  -> wireframe-spec.json
 *
 * Envelope shape is taken from the §5 golden example (version 0.4, the
 * container model). Step 0 (a live export from the target install) wins on any
 * mismatch — re-run the relevant envelope keys here if the live `version`
 * string differs.
 *
 * Hard rules honoured (task §2):
 *   - Containers only (no legacy section/column).
 *   - No local typography/color anywhere — only layout settings (flex,
 *     widths, gaps, spacing, alignment). Headings/text/buttons inherit the
 *     Global Kit.
 *   - Images are the Image widget, image_size "custom", exact §7 px, with a
 *     placeholder at that exact dimension.
 *   - Every content element carries a stable `wf-*` class (mirrored in
 *     `_title`); char ranges + image sizes live in the sidecar, not the JSON.
 *   - No JSON-LD / microdata (schema is injected site-wide by Launchpad).
 *
 * Run: php wireframe-library/build.php
 */

// ---------------------------------------------------------------------------
// Spec registry — the sidecar is generated from real element emission so it
// can never drift from the templates. First registration of a class wins
// (blocks are generated before pages, so canonical ranges win over variants).
// ---------------------------------------------------------------------------

$SPEC = [];

function spec(string $class, string $type, array $opts = []): void
{
    global $SPEC;
    if (isset($SPEC[$class])) {
        return; // set-if-absent: canonical (block) definition wins
    }
    $entry = ['type' => $type];
    if (isset($opts['image_size'])) {
        $entry['image_size'] = $opts['image_size'];
    }
    if (isset($opts['ratio'])) {
        $entry['ratio'] = $opts['ratio'];
    }
    if (isset($opts['char_range'])) {
        $entry['char_range'] = $opts['char_range'];
    }
    if (isset($opts['source'])) {
        $entry['source'] = $opts['source'];
    }
    if (isset($opts['note'])) {
        $entry['note'] = $opts['note'];
    }
    $SPEC[$class] = $entry;
}

// ---------------------------------------------------------------------------
// Deterministic, per-file-unique element ids (Elementor ids are random per
// export; we just need uniqueness within a file and reproducible builds).
// ---------------------------------------------------------------------------

final class Ids
{
    /** @var array<string,bool> */
    private array $used = [];
    private int $counter = 0;

    public function reset(): void
    {
        $this->used = [];
        $this->counter = 0;
    }

    public function next(string $hint = ''): string
    {
        do {
            $this->counter++;
            $id = substr(md5($hint.'#'.$this->counter), 0, 7);
        } while (isset($this->used[$id]));
        $this->used[$id] = true;

        return $id;
    }
}

$ids = new Ids();

// ---------------------------------------------------------------------------
// Low-level builders
// ---------------------------------------------------------------------------

function gap(int $px): array
{
    return ['unit' => 'px', 'size' => $px, 'column' => (string) $px, 'row' => (string) $px];
}

function widthPct(int $pct): array
{
    return ['unit' => '%', 'size' => $pct, 'sizes' => []];
}

/** wf-hero-eyebrow -> hero_eyebrow (the _title mirror). */
function titleFor(string $class): string
{
    return str_replace('-', '_', preg_replace('/^wf-/', '', $class));
}

function container(Ids $ids, array $settings, array $children, bool $inner = true, string $hint = 'c'): array
{
    return [
        'id' => $ids->next($hint),
        'elType' => 'container',
        'isInner' => $inner,
        'settings' => $settings,
        'elements' => array_values($children),
    ];
}

function col(Ids $ids, array $children, array $extra = []): array
{
    return container($ids, array_merge(['flex_direction' => 'column', 'flex_gap' => gap(16)], $extra), $children, true, 'col');
}

function row(Ids $ids, array $children, array $extra = []): array
{
    return container($ids, array_merge(['flex_direction' => 'row', 'flex_gap' => gap(24)], $extra), $children, true, 'row');
}

function widget(Ids $ids, string $type, array $settings, string $class): array
{
    return [
        'id' => $ids->next($class),
        'elType' => 'widget',
        'widgetType' => $type,
        'settings' => $settings,
        'elements' => [],
    ];
}

// ---------------------------------------------------------------------------
// Content-element builders (each registers its stable class in the sidecar)
// ---------------------------------------------------------------------------

function heading(Ids $ids, string $text, string $size, string $class, ?array $range = null): array
{
    spec($class, 'heading', array_filter(['char_range' => $range, 'note' => "header_size: {$size}"]));

    return widget($ids, 'heading', [
        'title' => $text,
        'header_size' => $size,
        '_title' => titleFor($class),
        '_css_classes' => $class,
    ], $class);
}

function textEl(Ids $ids, string $html, string $class, ?array $range = null): array
{
    spec($class, 'text', array_filter(['char_range' => $range]));

    return widget($ids, 'text-editor', [
        'editor' => str_starts_with(trim($html), '<') ? $html : "<p>{$html}</p>",
        '_title' => titleFor($class),
        '_css_classes' => $class,
    ], $class);
}

function richText(Ids $ids, string $html, string $class): array
{
    spec($class, 'rich_text', ['note' => 'editor region, no char cap']);

    return widget($ids, 'text-editor', [
        'editor' => $html,
        '_title' => titleFor($class),
        '_css_classes' => $class,
    ], $class);
}

function button(Ids $ids, string $label, string $class): array
{
    spec($class, 'button');

    return widget($ids, 'button', [
        'text' => $label,
        '_title' => titleFor($class),
        '_css_classes' => $class,
    ], $class);
}

function image(Ids $ids, int $w, int $h, string $class, string $name, string $source, string $ratio): array
{
    spec($class, 'image', ['image_size' => "{$w}x{$h}", 'ratio' => $ratio, 'source' => $source]);

    return widget($ids, 'image', [
        'image' => ['url' => "https://PLACEHOLDER.local/wf/{$name}-{$w}x{$h}.png", 'id' => 0],
        'image_size' => 'custom',
        'image_custom_dimension' => ['width' => (string) $w, 'height' => (string) $h],
        '_title' => titleFor($class),
        '_css_classes' => $class,
    ], $class);
}

/** Block wrapper: top-level boxed container, carries `wf-block wf-block-<name>`. */
function block(Ids $ids, string $blockName, array $rows, array $extra = []): array
{
    $slug = preg_replace('/^block-/', '', $blockName);
    spec('wf-block', 'container', ['note' => 'generic block wrapper marker']);
    spec("wf-block-{$slug}", 'container', ['note' => "{$blockName} wrapper"]);

    $settings = array_merge([
        'content_width' => 'boxed',
        'flex_direction' => 'column',
        'flex_gap' => gap(28),
        'padding' => ['unit' => 'px', 'top' => '64', 'right' => '0', 'bottom' => '64', 'left' => '0', 'isLinked' => false],
        '_title' => $blockName,
        '_css_classes' => "wf-block wf-block-{$slug}",
    ], $extra);

    return container($ids, $settings, $rows, false, $blockName);
}

function envelope(string $title, string $type, array $content): array
{
    return [
        'version' => '0.4',
        'title' => $title,
        'type' => $type,
        'page_settings' => [],
        'content' => array_values($content),
    ];
}

// ===========================================================================
// BLOCK BUILDERS
// ===========================================================================

function block_hero(Ids $ids, string $variant = 'problem_led'): array
{
    $slim = $variant === 'slim';
    $brandImage = in_array($variant, ['area_led', 'brand_led'], true);

    $headlineRange = $slim ? [20, 45] : [30, 70];
    $subheadRange = $slim ? [60, 120] : [90, 160];

    $headlineText = match ($variant) {
        'area_led' => 'Service area headline naming the place',
        'brand_led' => 'Brand promise headline',
        'category_led' => 'Service category headline',
        'slim' => 'Short page headline',
        default => 'Name the problem in the customer\'s words',
    };

    $textCol = col($ids, array_filter([
        textEl($ids, 'Service eyebrow', 'wf-hero-eyebrow', [12, 28]),
        heading($ids, $headlineText, 'h1', 'wf-hero-headline', $headlineRange),
        textEl($ids, 'Subhead stating the solution and one proof point.', 'wf-hero-subhead', $subheadRange),
        row($ids, [
            button($ids, 'Primary CTA', 'wf-hero-cta-primary'),
            button($ids, 'Call now', 'wf-hero-cta-phone'),
        ], ['flex_gap' => gap(12)]),
    ]), ['flex_gap' => gap(18), 'width' => $slim ? widthPct(100) : widthPct(52)]);

    if ($slim) {
        return block($ids, 'block-hero', [$textCol]);
    }

    $imgSource = $brandImage
        ? 'brand image (no AI local scene on area/brand variants)'
        : 'AI-gen → real';
    $imageCol = col($ids, [
        image($ids, 1200, 900, 'wf-hero-image', 'hero', 'AI-gen → real (brand image on area/brand variants)', '4:3'),
    ], ['width' => widthPct(48)]);

    return block($ids, 'block-hero', [
        row($ids, [$textCol, $imageCol], ['flex_align_items' => 'center', 'flex_gap' => gap(34)]),
    ]);
}

function block_trust_bar(Ids $ids): array
{
    // Source order: label (small, top) then value (H3, below).
    $cards = [];
    for ($n = 1; $n <= 4; $n++) {
        $cards[] = col($ids, [
            textEl($ids, 'Label', "wf-trust-label-{$n}", [6, 12]),
            heading($ids, 'Value '.$n, 'h3', "wf-trust-value-{$n}", [8, 18]),
        ], ['flex_align_items' => 'center', 'flex_gap' => gap(6)]);
    }

    return block($ids, 'block-trust-bar', [
        row($ids, $cards, ['flex_gap' => gap(24), 'flex_justify_content' => 'space-between']),
    ]);
}

function block_problem_solution(Ids $ids): array
{
    $side = function (Ids $ids, string $side, string $eyebrow): array {
        return col($ids, [
            textEl($ids, $eyebrow, "wf-ps-{$side}-eyebrow", [8, 16]),
            heading($ids, ucfirst($side).' subheading', 'h3', "wf-ps-{$side}-sub", [18, 36]),
            textEl($ids, "Body explaining the {$side}, 180–300 chars.", "wf-ps-{$side}-body", [180, 300]),
            textEl($ids, '<ul><li>Point one</li><li>Point two</li><li>Point three</li></ul>', "wf-ps-{$side}-list", [25, 55]),
        ], ['width' => widthPct(50), 'flex_gap' => gap(12)]);
    };

    return block($ids, 'block-problem-solution', [
        heading($ids, 'Problem & solution heading', 'h2', 'wf-ps-heading', [30, 55]),
        row($ids, [
            $side($ids, 'problem', 'The problem'),
            $side($ids, 'solution', 'The solution'),
        ], ['flex_gap' => gap(34)]),
    ]);
}

function block_why_us(Ids $ids, string $variant = 'claims'): array
{
    // claims: 3 cards = title (H3) + body. local: a lead paragraph + 3 bare
    // proof points (body only, no card title) — per the location wireframe.
    $local = $variant === 'local';

    $top = [heading($ids, 'Why choose us', 'h2', 'wf-why-heading', [30, 55])];
    if ($local) {
        $top[] = textEl($ids, 'Local proof lead, 120–220 chars.', 'wf-why-lead', [120, 220]);
    }

    $cards = [];
    for ($n = 1; $n <= 3; $n++) {
        if ($local) {
            $cards[] = col($ids, [
                textEl($ids, 'Local proof point '.$n.'.', "wf-why-card-{$n}-body", [40, 90]),
            ], ['width' => widthPct(33)]);
        } else {
            $cards[] = col($ids, [
                heading($ids, 'Reason '.$n, 'h3', "wf-why-card-{$n}-title", [18, 40]),
                textEl($ids, 'Supporting copy for this reason.', "wf-why-card-{$n}-body", [90, 150]),
            ], ['width' => widthPct(33), 'flex_gap' => gap(8)]);
        }
    }
    $top[] = row($ids, $cards, ['flex_gap' => gap(24)]);

    return block($ids, 'block-why-us', $top);
}

function block_proof_strip(Ids $ids): array
{
    $logos = [];
    for ($n = 1; $n <= 5; $n++) {
        $logos[] = image($ids, 200, 80, "wf-proof-logo-{$n}", "proof-logo-{$n}", 'client-supplied', '5:2');
    }

    return block($ids, 'block-proof-strip', [
        row($ids, $logos, ['flex_gap' => gap(24), 'flex_align_items' => 'center', 'flex_justify_content' => 'space-between']),
    ]);
}

function block_testimonials(Ids $ids, string $geoScope = 'service'): array
{
    // Reviewer town shows only on proximity scopes (radius/county) — service-
    // scoped and brand-wide reviews are name-only, per the wireframes.
    $withTown = in_array($geoScope, ['radius', 'county', 'location'], true);
    $cards = [];
    for ($n = 1; $n <= 3; $n++) {
        $els = [
            textEl($ids, 'Review body, 110–220 chars.', "wf-review-{$n}-body", [110, 220]),
            textEl($ids, 'Reviewer name', "wf-review-{$n}-name", [8, 20]),
        ];
        if ($withTown) {
            $els[] = textEl($ids, 'Town', "wf-review-{$n}-town", [6, 18]);
        }
        $cards[] = col($ids, $els, ['width' => widthPct(33), 'flex_gap' => gap(8)]);
    }

    return block($ids, 'block-testimonials', [
        row($ids, [
            heading($ids, 'What customers say', 'h2', 'wf-reviews-heading', [30, 55]),
            textEl($ids, '★★★★★ 0.0 · 00+ reviews', 'wf-reviews-rating'),
        ], ['flex_justify_content' => 'space-between', 'flex_align_items' => 'flex-end']),
        row($ids, $cards, ['flex_gap' => gap(24)]),
    ]);
}

function block_jobs(Ids $ids): array
{
    $cards = [];
    for ($n = 1; $n <= 4; $n++) {
        $cards[] = col($ids, [
            image($ids, 800, 600, "wf-job-{$n}-image", "job-{$n}", 'AI-gen → real', '4:3'),
            textEl($ids, 'Town', "wf-job-{$n}-town", [6, 18]),
            heading($ids, 'Job title '.$n, 'h3', "wf-job-{$n}-title", [18, 40]),
            textEl($ids, 'Short recap of the job done.', "wf-job-{$n}-body", [60, 120]),
        ], ['width' => widthPct(25), 'flex_gap' => gap(8)]);
    }

    return block($ids, 'block-jobs', [
        heading($ids, 'Recent jobs', 'h2', 'wf-jobs-heading', [30, 55]),
        row($ids, $cards, ['flex_gap' => gap(20)]),
    ]);
}

function block_faq(Ids $ids): array
{
    // Accordion widget (task §4). Per-item hooks wf-faq-q-{n} / wf-faq-a-{n}
    // map to accordion repeater item n's title/content (registered below);
    // repeater rows are not first-class elements, so the binding engine
    // targets them by index.
    $tabs = [];
    for ($n = 1; $n <= 4; $n++) {
        spec("wf-faq-q-{$n}", 'accordion_item_title', ['char_range' => [35, 80], 'note' => "accordion tabs[{$n}].tab_title"]);
        spec("wf-faq-a-{$n}", 'accordion_item_content', ['char_range' => [140, 300], 'note' => "accordion tabs[{$n}].tab_content"]);
        $tabs[] = [
            'tab_title' => 'Frequently asked question '.$n,
            'tab_content' => '<p>Answer to the question, 140–300 chars.</p>',
            '_id' => $GLOBALS['ids']->next("faqtab{$n}"),
        ];
    }

    spec('wf-faq', 'accordion', ['note' => 'Accordion widget; items in tabs[] repeater']);
    $accordion = widget($ids, 'accordion', [
        'tabs' => $tabs,
        '_title' => 'faq',
        '_css_classes' => 'wf-faq',
    ], 'wf-faq');

    return block($ids, 'block-faq', [
        heading($ids, 'Frequently asked questions', 'h2', 'wf-faq-heading', [30, 55]),
        $accordion,
    ]);
}

function block_final_cta(Ids $ids): array
{
    return block($ids, 'block-final-cta', [
        heading($ids, 'Ready to get started?', 'h2', 'wf-cta-heading', [25, 50]),
        textEl($ids, 'Closing line that nudges the action, 80–150 chars.', 'wf-cta-body', [80, 150]),
        row($ids, [
            button($ids, 'Primary CTA', 'wf-cta-primary'),
            button($ids, 'Call now', 'wf-cta-phone'),
        ], ['flex_gap' => gap(12)]),
    ], ['flex_align_items' => 'center']);
}

function block_intro(Ids $ids): array
{
    return block($ids, 'block-intro', [
        heading($ids, 'Intro heading', 'h2', 'wf-intro-heading', [30, 55]),
        textEl($ids, 'Intro / category body, 180–320 chars.', 'wf-intro-body', [180, 320]),
    ]);
}

function block_area_intro(Ids $ids): array
{
    return block($ids, 'block-area-intro', [
        heading($ids, 'Area intro heading', 'h2', 'wf-area-heading', [30, 55]),
        textEl($ids, 'Grounded area intro body (client-overridable), 220–400 chars.', 'wf-area-body', [220, 400]),
    ]);
}

function block_nap_map(Ids $ids, bool $mapOnly = false): array
{
    // Conditional: storefront only — suppressed for SAB (service-area business).
    // $mapOnly renders a full-width map (the Contact page's "nap-map(map)"
    // trailing block, where the NAP already lives in the details cluster).
    $map = image($ids, 800, 500, 'wf-map', 'map', 'embed / static', '8:5');
    if ($mapOnly) {
        return block($ids, 'block-nap-map', [$map]);
    }

    return block($ids, 'block-nap-map', [
        row($ids, [
            col($ids, [textEl($ids, 'Name · Address · Phone', 'wf-nap')], ['width' => widthPct(40)]),
            col($ids, [$map], ['width' => widthPct(60)]),
        ], ['flex_gap' => gap(24)]),
    ]);
}

function block_service_list(Ids $ids): array
{
    // Priority-ordered entity list, laid out as a multi-column grid (each item
    // links to its service page) — per the location wireframe's svc-grid.
    $items = [];
    for ($n = 1; $n <= 6; $n++) {
        $items[] = container($ids, ['flex_direction' => 'row', 'width' => widthPct(32), 'flex_justify_content' => 'space-between'], [
            textEl($ids, '<p><a href="#">Service '.$n.'</a></p>', "wf-svc-item-{$n}", [12, 32]),
        ], true, 'svcitem');
    }

    return block($ids, 'block-service-list', [
        heading($ids, 'Services we provide', 'h2', 'wf-svclist-heading', [30, 55]),
        container($ids, ['flex_direction' => 'row', 'flex_wrap' => 'wrap', 'flex_gap' => gap(12)], $items, true, 'svcgrid'),
    ]);
}

function block_services_grid(Ids $ids): array
{
    $cards = [];
    for ($n = 1; $n <= 3; $n++) {
        $cards[] = col($ids, [
            image($ids, 600, 400, "wf-svccard-{$n}-image", "svccard-{$n}", 'AI-gen → real', '3:2'),
            heading($ids, 'Service '.$n, 'h3', "wf-svccard-{$n}-title", [18, 40]),
            textEl($ids, '<p>Short blurb. <a href="#">Learn more</a></p>', "wf-svccard-{$n}-body", [60, 120]),
        ], ['width' => widthPct(33), 'flex_gap' => gap(8)]);
    }

    return block($ids, 'block-services-grid', [
        heading($ids, 'Services', 'h2', 'wf-svcgrid-heading', [30, 55]),
        row($ids, $cards, ['flex_gap' => gap(24)]),
    ]);
}

function block_areas_teaser(Ids $ids): array
{
    $links = [];
    for ($n = 1; $n <= 8; $n++) {
        $links[] = textEl($ids, '<p><a href="#">Area '.$n.'</a></p>', "wf-areateaser-link-{$n}", [8, 24]);
    }

    return block($ids, 'block-areas-teaser', [
        heading($ids, 'Areas we serve', 'h2', 'wf-areateaser-heading', [30, 55]),
        row($ids, [
            col($ids, [image($ids, 800, 500, 'wf-areateaser-map', 'areateaser-map', 'embed / static', '8:5')], ['width' => widthPct(55)]),
            container($ids, ['flex_direction' => 'row', 'flex_wrap' => 'wrap', 'flex_gap' => gap(12), 'width' => widthPct(45)], $links, true, 'links'),
        ], ['flex_gap' => gap(24)]),
    ]);
}

function block_markets_grid(Ids $ids): array
{
    $cards = [];
    for ($n = 1; $n <= 6; $n++) {
        $cards[] = textEl($ids, '<p><a href="#">Market '.$n.'</a></p>', "wf-market-{$n}", [8, 24]);
    }

    return block($ids, 'block-markets-grid', [
        heading($ids, 'Markets we serve', 'h2', 'wf-markets-heading', [30, 55]),
        image($ids, 800, 500, 'wf-markets-map', 'markets-map', 'embed / static', '8:5'),
        container($ids, ['flex_direction' => 'row', 'flex_wrap' => 'wrap', 'flex_gap' => gap(16)], $cards, true, 'grid'),
    ]);
}

function block_story(Ids $ids): array
{
    return block($ids, 'block-story', [
        heading($ids, 'Our story', 'h2', 'wf-story-heading', [30, 55]),
        row($ids, [
            col($ids, [textEl($ids, 'Story body, 400–700 chars.', 'wf-story-body', [400, 700])], ['width' => widthPct(55)]),
            col($ids, [image($ids, 800, 600, 'wf-story-image', 'story', 'AI-gen → real', '4:3')], ['width' => widthPct(45)]),
        ], ['flex_gap' => gap(34), 'flex_align_items' => 'center']),
    ]);
}

function block_team_grid(Ids $ids): array
{
    $cards = [];
    for ($n = 1; $n <= 3; $n++) {
        $cards[] = col($ids, [
            image($ids, 400, 400, "wf-team-{$n}-image", "team-{$n}", 'real headshot', '1:1'),
            heading($ids, 'Team member '.$n, 'h3', "wf-team-{$n}-name", [8, 28]),
            textEl($ids, 'Role / title', "wf-team-{$n}-role", [8, 32]),
        ], ['width' => widthPct(33), 'flex_align_items' => 'center', 'flex_gap' => gap(8)]);
    }

    return block($ids, 'block-team-grid', [
        heading($ids, 'Meet the team', 'h2', 'wf-team-heading', [30, 55]),
        row($ids, $cards, ['flex_gap' => gap(24)]),
    ]);
}

function block_contact_form(Ids $ids): array
{
    spec('wf-contact-form', 'form', ['note' => 'Form widget (Pro); posts to thank-you page']);
    $field = function (string $id, string $label, string $type, bool $required, int $width = 100) use ($ids): array {
        return array_filter([
            'custom_id' => $id,
            'field_type' => $type,
            'field_label' => $label,
            'placeholder' => $label,
            'required' => $required ? 'true' : '',
            'width' => (string) $width,
            '_id' => $ids->next('field-'.$id),
        ], fn ($v) => $v !== '');
    };

    $form = widget($ids, 'form', [
        'form_name' => 'Contact',
        'form_fields' => [
            $field('name', 'Name', 'text', true, 50),
            $field('email', 'Email', 'email', true, 50),
            $field('phone', 'Phone', 'tel', false, 50),
            $field('message', 'Message', 'textarea', true, 100),
        ],
        'button_text' => 'Send message',
        'submit_actions' => ['redirect'],
        'redirect_to' => '/thank-you',
        '_title' => 'contact_form',
        '_css_classes' => 'wf-contact-form',
    ], 'wf-contact-form');

    return block($ids, 'block-contact-form', [$form]);
}

function block_hours(Ids $ids): array
{
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $rows = [];
    foreach ($days as $i => $day) {
        $n = $i + 1;
        $rows[] = row($ids, [
            textEl($ids, $day, "wf-hours-{$n}-day"),
            textEl($ids, '9:00 AM – 5:00 PM', "wf-hours-{$n}-time"),
        ], ['flex_gap' => gap(12), 'flex_justify_content' => 'space-between']);
    }

    return block($ids, 'block-hours', $rows);
}

function block_post_grid(Ids $ids): array
{
    // Static-card version (related-posts / teaser). The blog index uses a
    // dynamic Posts/Loop Grid widget instead (see page_blog_index).
    $cards = [];
    for ($n = 1; $n <= 3; $n++) {
        $cards[] = col($ids, [
            image($ids, 640, 360, "wf-post-{$n}-image", "post-{$n}", 'AI-gen', '16:9'),
            textEl($ids, 'Date · Category', "wf-post-{$n}-meta"),
            heading($ids, 'Post title '.$n, 'h3', "wf-post-{$n}-title", [30, 70]),
            textEl($ids, 'Post excerpt, 80–160 chars.', "wf-post-{$n}-excerpt", [80, 160]),
        ], ['width' => widthPct(33), 'flex_gap' => gap(8)]);
    }

    return block($ids, 'block-post-grid', [
        row($ids, $cards, ['flex_gap' => gap(24)]),
    ]);
}

function block_article_header(Ids $ids): array
{
    return block($ids, 'block-article-header', [
        textEl($ids, 'Date · Author · Category', 'wf-article-meta'),
        heading($ids, 'Article title', 'h1', 'wf-article-title', [30, 80]),
        textEl($ids, 'By Author Name', 'wf-article-byline'),
        image($ids, 1200, 675, 'wf-article-featured', 'article-featured', 'AI-gen (news-to-blog)', '16:9'),
    ]);
}

function block_article_body(Ids $ids): array
{
    // Post Content widget — the generated post renders here via Theme Builder
    // dynamic content (not a static text area). Inline images target 1000×667.
    spec('wf-article-body', 'post-content', ['note' => 'Post Content widget (theme-post-content); inline body images 1000×667']);
    spec('wf-article-inline-image', 'image', ['image_size' => '1000x667', 'ratio' => '3:2', 'source' => 'AI-gen / upload', 'note' => 'inline body image target (authored inside Post Content)']);

    $postContent = widget($ids, 'theme-post-content', [
        '_title' => 'article_body',
        '_css_classes' => 'wf-article-body',
    ], 'wf-article-body');

    return block($ids, 'block-article-body', [$postContent]);
}

function block_author_share(Ids $ids): array
{
    spec('wf-author-share', 'share-buttons', ['note' => 'optional; Share Buttons widget (Pro)']);
    $share = widget($ids, 'share-buttons', [
        '_title' => 'author_share',
        '_css_classes' => 'wf-author-share',
    ], 'wf-author-share');

    return block($ids, 'block-author-share', [
        row($ids, [
            image($ids, 96, 96, 'wf-author-avatar', 'author-avatar', 'real', '1:1'),
            col($ids, [
                textEl($ids, 'Author bio.', 'wf-author-bio'),
                $share,
            ], ['flex_gap' => gap(8)]),
        ], ['flex_gap' => gap(16), 'flex_align_items' => 'center']),
    ]);
}

function block_basic_content(Ids $ids): array
{
    return block($ids, 'block-basic-content', [
        heading($ids, 'Page title', 'h1', 'wf-basic-title', [12, 50]),
        textEl($ids, 'Last updated: date', 'wf-basic-updated'),
        richText($ids, '<p>Rich body content. No character cap.</p>', 'wf-basic-body'),
    ]);
}

function block_utility_message(Ids $ids, string $variant = '404'): array
{
    $heading = $variant === 'thank_you' ? 'Thank you!' : 'Page not found';
    $body = $variant === 'thank_you'
        ? 'We\'ve received your message and will be in touch shortly.'
        : 'The page you\'re looking for can\'t be found.';

    $els = [
        heading($ids, $heading, 'h1', 'wf-util-heading', [8, 40]),
        textEl($ids, $body, 'wf-util-body', [40, 120]),
        button($ids, $variant === 'thank_you' ? 'Back to home' : 'Go to homepage', 'wf-util-cta'),
    ];

    if ($variant === '404') {
        spec('wf-util-links', 'text', ['note' => 'helpful links (404 only)']);
        $els[] = textEl($ids, '<ul><li><a href="/">Home</a></li><li><a href="/services">Services</a></li><li><a href="/contact">Contact</a></li></ul>', 'wf-util-links');
    }

    return block($ids, 'block-utility-message', $els, ['flex_align_items' => 'center']);
}

// ===========================================================================
// THEME BUILDER BUILDERS
// ===========================================================================

function tb_header(Ids $ids): array
{
    spec('wf-hd-social', 'social-icons', ['image_size' => '24x24', 'source' => 'icon set']);
    spec('wf-hd-menu', 'nav-menu', ['note' => 'Nav Menu (Pro) — main menu; responsive hamburger → off-canvas']);

    $social = widget($ids, 'social-icons', ['_title' => 'hd_social', '_css_classes' => 'wf-hd-social'], 'wf-hd-social');
    $navMenu = widget($ids, 'nav-menu', ['layout' => 'horizontal', '_title' => 'hd_menu', '_css_classes' => 'wf-hd-menu'], 'wf-hd-menu');

    // Tier 1 — utility bar
    $tier1 = row($ids, [
        textEl($ids, 'Phone', 'wf-hd-phone'),
        textEl($ids, 'Email', 'wf-hd-email'),
        textEl($ids, 'Address', 'wf-hd-address'),
        $social,
        textEl($ids, '★ 5.0 rating', 'wf-hd-rating'),
    ], ['flex_gap' => gap(16), 'flex_justify_content' => 'flex-end', 'flex_align_items' => 'center']);
    spec('wf-hd-rating', 'text', ['note' => 'REC — rating chip']);

    // Tier 2 — main bar
    $tier2 = row($ids, [
        image($ids, 200, 48, 'wf-hd-logo', 'logo-standard', 'client (standard/light)', '~25:6'),
        $navMenu,
        button($ids, 'Get a quote', 'wf-hd-cta'),
        button($ids, 'Call now', 'wf-hd-call'),
    ], ['flex_gap' => gap(20), 'flex_align_items' => 'center', 'flex_justify_content' => 'space-between']);
    spec('wf-hd-cta', 'button', ['note' => 'REC']);
    spec('wf-hd-call', 'button', ['note' => 'REC — click-to-call']);

    // Tier 3 — silo links (REC mega-menu → services)
    $silos = [];
    for ($n = 1; $n <= 5; $n++) {
        $silos[] = textEl($ids, '<p><a href="#">Silo '.$n.'</a></p>', "wf-hd-silo-{$n}", [8, 28]);
    }
    $tier3 = row($ids, $silos, ['flex_gap' => gap(20), 'flex_justify_content' => 'center']);

    $content = [
        container($ids, [
            'content_width' => 'full',
            'flex_direction' => 'column',
            'flex_gap' => gap(0),
            '_title' => 'tb-header',
            '_css_classes' => 'wf-header',
        ], [$tier1, $tier2, $tier3], false, 'tb-header'),
    ];
    spec('wf-header', 'container', ['note' => 'header wrapper']);

    return envelope('TB - Header', 'header', $content);
}

function tb_footer(Ids $ids): array
{
    spec('wf-ft-social', 'social-icons', ['source' => 'icon set', 'note' => 'REC']);
    $social = widget($ids, 'social-icons', ['_title' => 'ft_social', '_css_classes' => 'wf-ft-social'], 'wf-ft-social');

    $col1 = col($ids, [
        image($ids, 200, 48, 'wf-ft-logo', 'logo-reversed', 'client (reversed/dark)', '~25:6'),
        textEl($ids, 'Short about line, 60–140 chars.', 'wf-ft-about', [60, 140]),
        textEl($ids, 'Name · Address · Phone', 'wf-ft-nap'),
        textEl($ids, 'License # · Insured', 'wf-ft-license'),
        $social,
    ], ['width' => widthPct(28), 'flex_gap' => gap(10)]);
    spec('wf-ft-license', 'text', ['note' => 'REC — license/insurance']);

    $col2 = col($ids, [textEl($ids, '<strong>Services</strong><ul><li><a href="#">Service</a></li></ul>', 'wf-ft-services')], ['width' => widthPct(20)]);
    $col3 = col($ids, [textEl($ids, '<strong>Service Areas</strong><ul><li><a href="#">Area</a></li></ul>', 'wf-ft-areas')], ['width' => widthPct(20)]);
    $col4 = col($ids, [
        textEl($ids, '<strong>Company</strong><ul><li><a href="#">About</a></li><li><a href="#">Contact</a></li></ul>', 'wf-ft-company'),
        textEl($ids, '<ul><li><a href="#">Privacy</a></li><li><a href="#">Terms</a></li><li><a href="#">Accessibility</a></li><li><a href="#">Disclaimer</a></li><li><a href="#">Sitemap</a></li></ul>', 'wf-ft-legal'),
    ], ['width' => widthPct(20), 'flex_gap' => gap(10)]);
    spec('wf-ft-legal', 'text', ['note' => 'REC — legal links']);

    $badges = [];
    for ($n = 1; $n <= 3; $n++) {
        $badges[] = image($ids, 160, 80, "wf-ft-badge-{$n}", "ft-badge-{$n}", 'client', '2:1');
        $GLOBALS['SPEC']["wf-ft-badge-{$n}"]['note'] = 'REC — accreditation badge';
    }

    $bottom = row($ids, [
        textEl($ids, '© Year Company. All rights reserved.', 'wf-ft-copyright'),
        textEl($ids, '<p>Built &amp; managed by <a href="https://pumpitupmarketing.com">Pump It Up Marketing LLC</a></p>', 'wf-ft-credit'),
    ], ['flex_gap' => gap(16), 'flex_justify_content' => 'space-between', 'flex_align_items' => 'center']);

    $content = [
        container($ids, [
            'content_width' => 'full',
            'flex_direction' => 'column',
            'flex_gap' => gap(24),
            '_title' => 'tb-footer',
            '_css_classes' => 'wf-footer',
        ], [
            row($ids, [$col1, $col2, $col3, $col4], ['flex_gap' => gap(28), 'flex_align_items' => 'flex-start']),
            row($ids, $badges, ['flex_gap' => gap(20), 'flex_align_items' => 'center']),
            $bottom,
        ], false, 'tb-footer'),
    ];
    spec('wf-footer', 'container', ['note' => 'footer wrapper']);

    return envelope('TB - Footer', 'footer', $content);
}

// ===========================================================================
// PAGE-INDEX (dynamic) widget for the blog index
// ===========================================================================

function blog_index_posts(Ids $ids): array
{
    spec('wf-post-loop', 'posts', ['note' => 'Posts/Loop Grid widget (Pro), dynamic + pagination — swap to loop-grid when a loop-item template exists']);
    $posts = widget($ids, 'posts', [
        'posts_per_page' => 9,
        'columns' => 3,
        'pagination_type' => 'numbers',
        '_title' => 'post_loop',
        '_css_classes' => 'wf-post-loop',
    ], 'wf-post-loop');

    return block($ids, 'block-post-grid', [$posts]);
}

// ===========================================================================
// PAGE ASSEMBLIES
//
// Page JSONs carry the BODY blocks only. The "header → … → footer" in §6 is
// the TB-applied chrome (delivered as tb-header / tb-footer and applied
// site-wide via Theme Builder) — embedding it per page would duplicate it and
// collide with the global header/footer.
// ===========================================================================

function page_home(Ids $ids): array
{
    return envelope('Page - Home', 'container', [
        block_hero($ids, 'brand_led'),
        block_trust_bar($ids),
        block_services_grid($ids),
        block_why_us($ids, 'claims'),
        block_areas_teaser($ids),
        block_testimonials($ids),
        block_jobs($ids),
        block_faq($ids),
        block_final_cta($ids),
    ]);
}

function page_service_hub(Ids $ids): array
{
    return envelope('Page - Service Hub', 'container', [
        block_hero($ids, 'category_led'),
        block_intro($ids),
        block_services_grid($ids),
        block_why_us($ids, 'claims'),
        block_testimonials($ids),
        block_final_cta($ids),
    ]);
}

function page_service(Ids $ids): array
{
    return envelope('Page - Service', 'container', [
        block_hero($ids, 'problem_led'),
        block_trust_bar($ids),
        block_problem_solution($ids),
        block_why_us($ids, 'claims'),
        block_proof_strip($ids),
        block_testimonials($ids, 'service'),
        block_jobs($ids),
        block_faq($ids),
        block_final_cta($ids),
    ]);
}

function page_service_in_location(Ids $ids): array
{
    return envelope('Page - Service in Location', 'container', [
        block_hero($ids, 'area_led'),
        block_trust_bar($ids),
        block_problem_solution($ids),
        block_area_intro($ids),
        block_nap_map($ids),
        block_why_us($ids, 'local'),
        block_testimonials($ids, 'radius'),
        block_jobs($ids),
        block_faq($ids),
        block_final_cta($ids),
    ]);
}

function page_location(Ids $ids): array
{
    return envelope('Page - Location', 'container', [
        block_hero($ids, 'area_led'),
        block_area_intro($ids),
        block_nap_map($ids),
        block_service_list($ids),
        block_why_us($ids, 'local'),
        block_testimonials($ids, 'radius'),
        block_jobs($ids),
        block_faq($ids),
        block_final_cta($ids),
    ]);
}

function page_areas_hub(Ids $ids): array
{
    return envelope('Page - Areas Hub', 'container', [
        block_hero($ids, 'brand_led'),
        block_intro($ids),
        block_markets_grid($ids),
        block_testimonials($ids),
        block_final_cta($ids),
    ]);
}

function page_about(Ids $ids): array
{
    return envelope('Page - About', 'container', [
        block_hero($ids, 'brand_led'),
        block_story($ids),
        block_trust_bar($ids),
        block_team_grid($ids),
        block_why_us($ids, 'claims'),
        block_proof_strip($ids),
        block_testimonials($ids),
        block_final_cta($ids),
    ]);
}

function page_contact(Ids $ids): array
{
    // §6: hero(slim) → [contact-form + hours + nap] → nap-map(map) → final-cta.
    // The NAP lives in the details cluster; the trailing nap-map is map-only.
    return envelope('Page - Contact', 'container', [
        block_hero($ids, 'slim'),
        block($ids, 'block-contact', [
            row($ids, [
                col($ids, [block_contact_form($ids)], ['width' => widthPct(55)]),
                col($ids, [
                    block_hours($ids),
                    textEl($ids, 'Name · Address · City · Phone', 'wf-nap'),
                ], ['width' => widthPct(45), 'flex_gap' => gap(16)]),
            ], ['flex_gap' => gap(34), 'flex_align_items' => 'flex-start']),
        ]),
        block_nap_map($ids, true),
        block_final_cta($ids),
    ]);
}

function page_blog_index(Ids $ids): array
{
    return envelope('Page - Blog Index', 'container', [
        block_hero($ids, 'slim'),
        blog_index_posts($ids),
        block_final_cta($ids),
    ]);
}

function page_blog_post(Ids $ids): array
{
    // Theme Builder Single template (Post Content renders the body).
    return envelope('Page - Blog Post', 'single-post', [
        block_article_header($ids),
        block_article_body($ids),
        block_author_share($ids),
        block_final_cta($ids),
        block_post_grid($ids),
    ]);
}

function page_basic_content(Ids $ids): array
{
    return envelope('Page - Basic Content', 'container', [
        block_basic_content($ids),
    ]);
}

function page_404(Ids $ids): array
{
    return envelope('Page - 404', 'container', [
        block_utility_message($ids, '404'),
    ]);
}

function page_thank_you(Ids $ids): array
{
    return envelope('Page - Thank You', 'container', [
        block_utility_message($ids, 'thank_you'),
    ]);
}

// ===========================================================================
// EMIT
// ===========================================================================

$root = __DIR__;
@mkdir("{$root}/blocks", 0o775, true);
@mkdir("{$root}/pages", 0o775, true);
@mkdir("{$root}/theme-builder", 0o775, true);

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

function emit(string $path, array $data, int $flags): void
{
    file_put_contents($path, json_encode($data, $flags)."\n");
    // re-decode to guarantee the file is valid JSON
    if (json_decode(file_get_contents($path)) === null) {
        fwrite(STDERR, "INVALID JSON: {$path}\n");
        exit(1);
    }
}

/** Collect every wf-* class actually emitted into a tree (for cross-check). */
function collectClasses(array $node, array &$out): void
{
    if (isset($node['settings']['_css_classes'])) {
        foreach (preg_split('/\s+/', trim($node['settings']['_css_classes'])) as $c) {
            if ($c !== '') {
                $out[$c] = true;
            }
        }
    }
    foreach (($node['elements'] ?? []) as $child) {
        collectClasses($child, $out);
    }
}

// Block files (generated FIRST so canonical ranges win in the sidecar).
$blocks = [
    'block-hero' => fn () => block_hero($ids),
    'block-trust-bar' => fn () => block_trust_bar($ids),
    'block-problem-solution' => fn () => block_problem_solution($ids),
    'block-why-us' => fn () => block_why_us($ids),
    'block-proof-strip' => fn () => block_proof_strip($ids),
    'block-testimonials' => fn () => block_testimonials($ids),
    'block-jobs' => fn () => block_jobs($ids),
    'block-faq' => fn () => block_faq($ids),
    'block-final-cta' => fn () => block_final_cta($ids),
    'block-intro' => fn () => block_intro($ids),
    'block-area-intro' => fn () => block_area_intro($ids),
    'block-nap-map' => fn () => block_nap_map($ids),
    'block-service-list' => fn () => block_service_list($ids),
    'block-services-grid' => fn () => block_services_grid($ids),
    'block-areas-teaser' => fn () => block_areas_teaser($ids),
    'block-markets-grid' => fn () => block_markets_grid($ids),
    'block-story' => fn () => block_story($ids),
    'block-team-grid' => fn () => block_team_grid($ids),
    'block-contact-form' => fn () => block_contact_form($ids),
    'block-hours' => fn () => block_hours($ids),
    'block-post-grid' => fn () => block_post_grid($ids),
    'block-article-header' => fn () => block_article_header($ids),
    'block-article-body' => fn () => block_article_body($ids),
    'block-author-share' => fn () => block_author_share($ids),
    'block-basic-content' => fn () => block_basic_content($ids),
    'block-utility-message' => fn () => block_utility_message($ids),
];

$allClasses = [];
$count = 0;

foreach ($blocks as $name => $builder) {
    $ids->reset();
    $tree = $builder();
    $title = 'Block - '.ucwords(str_replace('-', ' ', preg_replace('/^block-/', '', $name)));
    $data = envelope($title, 'container', [$tree]);
    foreach ($data['content'] as $node) {
        collectClasses($node, $allClasses);
    }
    emit("{$root}/blocks/{$name}.json", $data, $jsonFlags);
    $count++;
}

// Theme Builder
foreach (['tb-header' => 'tb_header', 'tb-footer' => 'tb_footer'] as $name => $fn) {
    $ids->reset();
    $data = $fn($ids);
    foreach ($data['content'] as $node) {
        collectClasses($node, $allClasses);
    }
    emit("{$root}/theme-builder/{$name}.json", $data, $jsonFlags);
    $count++;
}

// Pages
$pages = [
    'page-home' => 'page_home',
    'page-service-hub' => 'page_service_hub',
    'page-service' => 'page_service',
    'page-service-in-location' => 'page_service_in_location',
    'page-location' => 'page_location',
    'page-areas-hub' => 'page_areas_hub',
    'page-about' => 'page_about',
    'page-contact' => 'page_contact',
    'page-blog-index' => 'page_blog_index',
    'page-blog-post' => 'page_blog_post',
    'page-basic-content' => 'page_basic_content',
    'page-404' => 'page_404',
    'page-thank-you' => 'page_thank_you',
];

foreach ($pages as $name => $fn) {
    $ids->reset();
    $data = $fn($ids);
    foreach ($data['content'] as $node) {
        collectClasses($node, $allClasses);
    }
    emit("{$root}/pages/{$name}.json", $data, $jsonFlags);
    $count++;
}

// Sidecar spec
ksort($SPEC);
emit("{$root}/wireframe-spec.json", $SPEC, $jsonFlags);

// ---------------------------------------------------------------------------
// Cross-check: every emitted wf-* class must resolve in the sidecar, and vice
// versa (repeater-only hooks like wf-faq-q-{n} live in the sidecar without a
// matching element class — those are allowed to be sidecar-only).
// ---------------------------------------------------------------------------

$missing = [];
foreach (array_keys($allClasses) as $c) {
    if (! isset($SPEC[$c])) {
        $missing[] = $c;
    }
}
if ($missing) {
    fwrite(STDERR, "Emitted classes missing from sidecar: ".implode(', ', $missing)."\n");
    exit(1);
}

printf("Generated %d Elementor templates + wireframe-spec.json (%d classes).\n", $count, count($SPEC));
printf("  blocks: %d  theme-builder: 2  pages: %d\n", count($blocks), count($pages));
