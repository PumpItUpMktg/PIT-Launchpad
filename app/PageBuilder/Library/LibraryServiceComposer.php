<?php

namespace App\PageBuilder\Library;

/**
 * Composes the SERVICE page body from the wireframe library: clone the page-service
 * assembly → normalize to the live shape → inject §3a content into the `wf-*` hooks
 * → return the `_elementor_data` tree. The approved slot→hook map for the service
 * body lives here (the clean-mapping blocks: hero / trust-bar / problem-solution /
 * why-us / testimonials / faq / final-cta).
 *
 * Deferred per the migration plan: service_features / process_steps stay on the
 * hand-built emitter (they need a features block inserted into the assembly —
 * Phase 2.5); jobs + proof-strip logos are dropped (no §3a source); contact_block
 * lives on the location page.
 */
final class LibraryServiceComposer
{
    public function __construct(
        private readonly BlockLibrary $library,
        private readonly TargetNormalizer $normalizer,
        private readonly HookInjector $injector,
    ) {}

    /**
     * @param  array<string, mixed>  $slots  resolved slot_payload
     * @param  array<string, array<string, mixed>>  $images  image map keyed by slot
     * @return list<array<string, mixed>>
     */
    public function compose(array $slots, array $images = []): array
    {
        $page = $this->normalizer->normalize($this->library->page('service'));

        [$values, $faq, $dropBlocks] = $this->plan($slots, $images);
        $staticHeadings = (array) config('elementor_target.static_headings', []);

        return $this->injector->inject($page, $values, $dropBlocks, $faq, $staticHeadings);
    }

    public function warnings(): array
    {
        return $this->injector->warnings();
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     * @return array{0: array<string, array{mode: string, value: mixed}>, 1: list<array{question?: string, answer?: string}>, 2: list<string>}
     */
    private function plan(array $slots, array $images): array
    {
        $values = [];

        $text = function (string $hook, string $key) use (&$values, $slots): void {
            $v = $slots[$key] ?? null;
            $v = is_array($v) ? (string) ($v[0] ?? '') : (string) ($v ?? '');
            if (trim($v) !== '') {
                $values[$hook] = ['mode' => 'text', 'value' => $v];
            }
        };

        // Hero + body prose.
        $text('wf-hero-headline', 'hero_problem');
        $text('wf-hero-subhead', 'hero_solution');
        $text('wf-ps-problem-body', 'problem_explainer');
        $text('wf-ps-solution-body', 'solution_overview');
        $text('wf-why-card-1-body', 'why_us'); // local variant: blob → lead body; titles/cards 2-3 hidden

        // Hero image.
        $heroUrl = (string) ($images['hero_image']['url'] ?? (is_array($slots['hero_image'] ?? null) ? ($slots['hero_image']['url'] ?? '') : ''));
        if ($heroUrl !== '') {
            $values['wf-hero-image'] = ['mode' => 'image', 'value' => ['url' => $heroUrl]];
        }

        // proof_strip stats → trust-bar value/label (truncate to 4).
        foreach (array_slice($this->list($slots['proof_strip'] ?? null), 0, 4) as $i => $stat) {
            $n = $i + 1;
            if (trim((string) ($stat['value'] ?? '')) !== '') {
                $values["wf-trust-value-{$n}"] = ['mode' => 'text', 'value' => (string) $stat['value']];
                $values["wf-trust-label-{$n}"] = ['mode' => 'text', 'value' => (string) ($stat['label'] ?? '')];
            }
        }

        // testimonials → reviews (truncate to 3).
        foreach (array_slice($this->list($slots['testimonial'] ?? null), 0, 3) as $i => $t) {
            $n = $i + 1;
            $quote = (string) ($t['quote'] ?? $t['body'] ?? '');
            if (trim($quote) !== '') {
                $values["wf-review-{$n}-body"] = ['mode' => 'text', 'value' => $quote];
                $values["wf-review-{$n}-name"] = ['mode' => 'text', 'value' => (string) ($t['author'] ?? '')];
            }
        }

        // CTA conversion block → hero + final-cta buttons.
        $cta = is_array($slots['cta'] ?? null) ? $slots['cta'] : [];
        $tel = trim((string) ($cta['tel'] ?? ''));
        $label = trim((string) ($cta['call_label'] ?? 'Call Now'));
        $phone = trim((string) ($cta['phone'] ?? ''));
        if ($tel !== '') {
            $primary = ['mode' => 'button', 'value' => ['text' => $label, 'url' => $tel]];
            $phoneBtn = ['mode' => 'button', 'value' => ['text' => $phone !== '' ? $this->displayPhone($phone) : $label, 'url' => $tel]];
            $values['wf-hero-cta-primary'] = $primary;
            $values['wf-cta-primary'] = $primary;
            if ($phone !== '') {
                $values['wf-hero-cta-phone'] = $phoneBtn;
                $values['wf-cta-phone'] = $phoneBtn;
            }
        }

        $faq = $this->list($slots['faq'] ?? null);

        // Blocks with no §3a source on the service page → dropped (never ship placeholders).
        $dropBlocks = ['wf-block-jobs', 'wf-block-proof-strip'];

        return [$values, $faq, $dropBlocks];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * A human-readable phone for the call BUTTON's text (the tel: link keeps the raw
     * E.164). US 10/11-digit → (XXX) XXX-XXXX; anything else passes through unchanged.
     */
    private function displayPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }

        return trim($phone);
    }
}
