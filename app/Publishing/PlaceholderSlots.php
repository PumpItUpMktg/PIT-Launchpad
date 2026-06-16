<?php

namespace App\Publishing;

use App\Enums\SlotContentType;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Schema\SlotDefinition;

/**
 * Length-representative stand-in content for a kit, so the placeholder preview reads
 * like the real page — never one-word headings where real copy runs a sentence. Same
 * slot keys the composer expects; only the VALUES are stand-ins. Image slots are
 * handled via the image map (a placeholder box), so they're skipped here; the cta
 * gets a sample phone + a labeled form box.
 */
class PlaceholderSlots
{
    /** A labeled box the renderer shows in place of the real cross-origin form embed. */
    public const FORM_BOX = '<div class="lp-form-placeholder" style="display:flex;align-items:center;justify-content:center;min-height:320px;border:2px dashed currentColor;opacity:.6;font-weight:600">Form embed</div>';

    /**
     * @return array<string, mixed>
     */
    public function forSchema(KitSchema $schema): array
    {
        $slots = [];
        foreach ($schema->slots as $slot) {
            $value = $this->valueFor($slot);
            if ($value !== null) {
                $slots[$slot->key] = $value;
            }
        }

        return $slots;
    }

    private function valueFor(SlotDefinition $slot): mixed
    {
        return match ($slot->contentType) {
            SlotContentType::Image, SlotContentType::Gallery, SlotContentType::Map => null, // image map / not previewed here
            SlotContentType::Cta => [
                'type' => 'conversion_block',
                'call_label' => 'Call Now',
                'phone' => '(555) 123-4567',
                'tel' => 'tel:+15551234567',
                'form_embed' => self::FORM_BOX,
            ],
            SlotContentType::Faq => $this->repeat($slot, [
                ['question' => 'How long does the work usually take?', 'answer' => 'Most projects wrap in a single visit; larger jobs are scheduled within a few days and we keep you updated throughout.'],
                ['question' => 'Are you licensed and insured?', 'answer' => 'Yes — fully licensed, bonded, and insured, with warrantied workmanship on every job we complete.'],
                ['question' => 'Do you offer free estimates?', 'answer' => 'We do. Call or request a quote and we will give you a clear, no-obligation estimate up front.'],
            ]),
            SlotContentType::Stat => $this->repeat($slot, [
                ['value' => '20+', 'label' => 'Years in business'],
                ['value' => '4.9★', 'label' => 'Average rating'],
                ['value' => '5,000+', 'label' => 'Jobs completed'],
                ['value' => '24/7', 'label' => 'Emergency service'],
            ]),
            SlotContentType::Testimonial => $this->repeat($slot, [
                ['quote' => 'Prompt, professional, and tidy — they fixed the problem the same day and explained everything clearly.', 'author' => 'Jordan M.'],
                ['quote' => 'Fair pricing and excellent work. The team was respectful of our home and cleaned up after themselves.', 'author' => 'Riley T.'],
                ['quote' => 'Booked in the morning, done by afternoon. Honestly the easiest contractor experience we have had.', 'author' => 'Casey P.'],
            ]),
            SlotContentType::List => $this->repeat($slot, [
                'Upfront, transparent pricing with no surprise fees',
                'Licensed, background-checked technicians on every job',
                'Workmanship backed by a written guarantee',
                'Same-day and emergency appointments available',
            ]),
            SlotContentType::Heading => $this->heading($slot),
            SlotContentType::ShortText => 'A clear, reassuring sentence that sets up the section and gives the visitor confidence.',
            default => '<p>This is length-representative body copy that runs a realistic paragraph, so the layout, line length, and rhythm read exactly as they will with the real content in place. It spans a couple of sentences to mirror genuine prose.</p>',
        };
    }

    private function heading(SlotDefinition $slot): string
    {
        // Hero/headline slots get a fuller line; section headings a shorter one.
        return str_contains($slot->key, 'headline') || str_contains($slot->key, 'hero')
            ? 'Dependable Service You Can Count On, Done Right'
            : 'The problem — and how we fix it';
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function repeat(SlotDefinition $slot, array $items): array
    {
        $max = $slot->cardinality->max ?? count($items);
        $min = $slot->cardinality->min ?? 1;
        $count = max($min, min($max, count($items)));

        return array_slice($items, 0, max(1, $count));
    }
}
