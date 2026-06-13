<?php

namespace App\PageBuilder\Template;

/**
 * The outcome of checking a kit's Elementor template binds every slot it must:
 * which slot keys are bound (by an lp/* dynamic tag OR an [lp_*] shortcode) and
 * which REQUIRED slots are still unbound. A template passes only when no required
 * slot is missing.
 */
final class TemplateBindingResult
{
    /**
     * @param  list<string>  $boundSlots  slot keys the template binds
     * @param  list<string>  $missingRequired  required slot keys with no binding
     */
    public function __construct(
        public readonly array $boundSlots,
        public readonly array $missingRequired,
    ) {}

    public function passes(): bool
    {
        return $this->missingRequired === [];
    }
}
