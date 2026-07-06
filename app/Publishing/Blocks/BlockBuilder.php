<?php

namespace App\Publishing\Blocks;

/**
 * Emits CORE Gutenberg block markup (the `<!-- wp:… -->` comment + matching HTML) as `post_content`.
 * The Elementor→Gutenberg pivot's substrate: only core blocks (group / columns / heading / paragraph /
 * buttons / image / list / spacer), never a custom block, so the output is portable standard WordPress.
 * Colors/typography/radius come from the block theme's `theme.json` by palette SLUG (never inline hex),
 * so a page renders in whichever style variation is active and restyles when it changes.
 *
 * Every method returns a self-contained block string; containers take already-rendered child strings.
 */
final class BlockBuilder
{
    /** Join a list of block strings into one document. */
    public function render(array $blocks): string
    {
        return implode("\n\n", array_values(array_filter($blocks, fn ($b): bool => is_string($b) && trim($b) !== '')));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function heading(int $level, string $text, array $attrs = []): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $attrs = ['level' => $level] + $attrs;
        $classes = $this->classList('', $attrs);
        $classAttr = $classes !== '' ? ' class="'.$classes.'"' : '';

        return $this->comment('heading', $attrs)."\n<h{$level}{$classAttr}>".$this->esc($text)."</h{$level}>\n".$this->close('heading');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function paragraph(string $html, array $attrs = []): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $classes = $this->classList('', $attrs);
        $classAttr = $classes !== '' ? ' class="'.$classes.'"' : '';

        return $this->comment('paragraph', $attrs)."\n<p{$classAttr}>".$this->inlineHtml($html)."</p>\n".$this->close('paragraph');
    }

    /**
     * A group container. `attrs.backgroundColor` / `attrs.textColor` are theme palette slugs.
     *
     * @param  list<string>  $children
     * @param  array<string, mixed>  $attrs
     */
    public function group(array $children, array $attrs = []): string
    {
        $attrs = $attrs + ['layout' => ['type' => 'constrained']];
        $classes = $this->classList('wp-block-group', $attrs);

        return $this->comment('group', $attrs)
            ."\n<div class=\"{$classes}\">\n".$this->render($children)."\n</div>\n"
            .$this->close('group');
    }

    /**
     * @param  list<string>  $columns  already-rendered column blocks
     * @param  array<string, mixed>  $attrs
     */
    public function columns(array $columns, array $attrs = []): string
    {
        $classes = $this->classList('wp-block-columns', $attrs);

        return $this->comment('columns', $attrs)
            ."\n<div class=\"{$classes}\">\n".$this->render($columns)."\n</div>\n"
            .$this->close('columns');
    }

    /**
     * @param  list<string>  $children
     * @param  array<string, mixed>  $attrs
     */
    public function column(array $children, array $attrs = []): string
    {
        $classes = $this->classList('wp-block-column', $attrs);
        $style = isset($attrs['width']) ? ' style="flex-basis:'.$this->esc((string) $attrs['width']).'"' : '';

        return $this->comment('column', $attrs)
            ."\n<div class=\"{$classes}\"{$style}>\n".$this->render($children)."\n</div>\n"
            .$this->close('column');
    }

    /**
     * @param  list<array{text: string, url: string, attrs?: array<string, mixed>}>  $buttons
     * @param  array<string, mixed>  $attrs
     */
    public function buttons(array $buttons, array $attrs = []): string
    {
        $rendered = array_map(fn (array $b): string => $this->button($b['text'], $b['url'], $b['attrs'] ?? []), $buttons);
        $rendered = array_values(array_filter($rendered, fn (string $b): bool => $b !== ''));
        if ($rendered === []) {
            return '';
        }
        $classes = $this->classList('wp-block-buttons', $attrs);

        return $this->comment('buttons', $attrs)
            ."\n<div class=\"{$classes}\">\n".$this->render($rendered)."\n</div>\n"
            .$this->close('buttons');
    }

    /**
     * @param  array<string, mixed>  $attrs  backgroundColor / textColor (palette slugs); className "is-style-outline" for the secondary variant
     */
    public function button(string $text, string $url, array $attrs = []): string
    {
        $text = trim($text);
        $url = trim($url);
        if ($text === '' || $url === '') {
            return '';
        }
        $linkClasses = trim('wp-block-button__link '.$this->colorClasses($attrs).' wp-element-button');
        $wrapClasses = $this->classList('wp-block-button', ['className' => $attrs['className'] ?? null]);

        return $this->comment('button', $attrs)
            ."\n<div class=\"{$wrapClasses}\"><a class=\"".trim($linkClasses).'" href="'.$this->esc($url).'">'.$this->esc($text).'</a></div>'."\n"
            .$this->close('button');
    }

    public function image(string $url, string $alt, array $attrs = []): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $attrs = ['sizeSlug' => 'large'] + $attrs;
        $figureClasses = $this->classList('wp-block-image size-large', ['className' => $attrs['className'] ?? null]);

        return $this->comment('image', $attrs)
            ."\n<figure class=\"{$figureClasses}\"><img src=\"".$this->esc($url).'" alt="'.$this->esc($alt).'"/></figure>'."\n"
            .$this->close('image');
    }

    /**
     * @param  list<string>  $items  plain text or inline HTML
     * @param  array<string, mixed>  $attrs
     */
    public function list(array $items, array $attrs = []): string
    {
        $items = array_values(array_filter(array_map('trim', $items), fn (string $i): bool => $i !== ''));
        if ($items === []) {
            return '';
        }
        $rows = array_map(
            fn (string $i): string => "<!-- wp:list-item -->\n<li>".$this->inlineHtml($i)."</li>\n<!-- /wp:list-item -->",
            $items,
        );
        $classes = $this->classList('wp-block-list', $attrs);

        return $this->comment('list', $attrs)
            ."\n<ul class=\"{$classes}\">\n".$this->render($rows)."\n</ul>\n"
            .$this->close('list');
    }

    public function spacer(string $height = '2.5rem'): string
    {
        $attrs = ['height' => $height];

        return $this->comment('spacer', $attrs)
            .'<div style="height:'.$this->esc($height).'" aria-hidden="true" class="wp-block-spacer"></div>'
            .$this->close('spacer');
    }

    // ── internals ──

    /** @param array<string, mixed> $attrs */
    private function comment(string $block, array $attrs): string
    {
        $json = $this->attrJson($attrs);

        return $json === '' ? "<!-- wp:{$block} -->" : "<!-- wp:{$block} {$json} -->";
    }

    private function close(string $block): string
    {
        return "<!-- /wp:{$block} -->";
    }

    /**
     * The class list for a block wrapper: base + color classes + an optional caller className.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function classList(string $base, array $attrs): string
    {
        $parts = array_filter([
            $base,
            $this->colorClasses($attrs),
            is_string($attrs['className'] ?? null) ? $attrs['className'] : '',
        ], fn (string $p): bool => trim($p) !== '');

        return trim(implode(' ', $parts));
    }

    /** Map backgroundColor/textColor palette slugs to WP's has-*-color classes. */
    private function colorClasses(array $attrs): string
    {
        $classes = [];
        if (is_string($attrs['textColor'] ?? null) && $attrs['textColor'] !== '') {
            $classes[] = 'has-'.$attrs['textColor'].'-color';
            $classes[] = 'has-text-color';
        }
        if (is_string($attrs['backgroundColor'] ?? null) && $attrs['backgroundColor'] !== '') {
            $classes[] = 'has-'.$attrs['backgroundColor'].'-background-color';
            $classes[] = 'has-background';
        }

        return implode(' ', $classes);
    }

    /**
     * Serialize block attributes to the JSON the block comment carries. `className` is dropped (it
     * lives on the HTML wrapper, not the attribute list) and `width` is column-only sugar.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function attrJson(array $attrs): string
    {
        unset($attrs['className'], $attrs['width']);
        $attrs = array_filter($attrs, fn ($v): bool => $v !== null && $v !== '' && $v !== []);
        if ($attrs === []) {
            return '';
        }

        return (string) json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    /** Body text may already carry safe inline HTML (links/strong); leave tags, but never a raw script. */
    private function inlineHtml(string $html): string
    {
        return (string) preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $html);
    }
}
