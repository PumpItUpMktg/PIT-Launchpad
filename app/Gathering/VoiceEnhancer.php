<?php

namespace App\Gathering;

use App\Integrations\Claude\ClaudeClient;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;

/**
 * The opt-in AI shaping call for the voice draft — takes the operator's ROUGH notes (whatever
 * is typed in the form, however messy; empty is fine too) plus the site's context (brand,
 * trade, services) and returns a cleaned, structured profile. Mission-polish semantics:
 * keep the owner's meaning and their actual phrases, tighten and organize — never invent
 * business facts, guarantees, credentials, or claims that aren't in the notes.
 *
 * Returns FORM VALUES only — the caller fills the form; nothing persists until the operator
 * saves, and nothing writes pages until they activate. Fail-open: null on a thrown call or
 * unparseable reply.
 */
class VoiceEnhancer
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @param  array{persona: string, language_rules: string, audience: string, reading_level: string, cta_voice: string}  $current
     * @return array{persona: string, language_rules: string, audience: string, reading_level: string, cta_voice: string}|null
     */
    public function enhance(Site $site, array $current): ?array
    {
        $trade = (string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->value('trade') ?? '');
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->limit(12)->pluck('name')->implode(', ');

        $system = 'You shape a home-services brand\'s WRITING VOICE profile from the owner\'s rough notes. '
            .'Keep the owner\'s meaning and their actual phrases — tighten, organize, and fill obvious gaps with sensible '
            .'trade-appropriate defaults. Plain language throughout. NEVER invent business facts, guarantees, '
            .'credentials, prices, or claims that are not in the notes.';

        $prompt = "Business: {$site->brand_name}".($trade !== '' ? " — {$trade}" : '')."\n"
            .($services !== '' ? "Services: {$services}\n" : '')
            ."\nOwner's rough notes (may be messy or partly empty):\n"
            ."- How the site should sound: {$this->orNone($current['persona'])}\n"
            ."- Say / never say: {$this->orNone($current['language_rules'])}\n"
            ."- Who we're talking to: {$this->orNone($current['audience'])}\n"
            ."- Plain-language level: {$this->orNone($current['reading_level'])}\n"
            ."- How to ask for the call: {$this->orNone($current['cta_voice'])}\n\n"
            .'Respond as ONE JSON object: {"persona": "<1-2 sentences, who the site sounds like>", '
            .'"language_rules": ["<one rule per item — phrases to use, words to never use>"], '
            .'"audience": ["<one audience per item>"], "reading_level": "<short, e.g. everyday plain English>", '
            .'"cta_voice": "<short, e.g. direct but no pressure>"}. JSON only, no prose.';

        $data = $this->parse($this->safeComplete($prompt, $system));
        if ($data === null) {
            return null;
        }

        $out = [
            'persona' => trim((string) (is_string($data['persona'] ?? null) ? $data['persona'] : '')),
            'language_rules' => $this->lines($data['language_rules'] ?? null),
            'audience' => $this->lines($data['audience'] ?? null),
            'reading_level' => trim((string) (is_string($data['reading_level'] ?? null) ? $data['reading_level'] : '')),
            'cta_voice' => trim((string) (is_string($data['cta_voice'] ?? null) ? $data['cta_voice'] : '')),
        ];

        // A reply with no usable persona AND no rules is a failure, not an enhancement.
        return $out['persona'] === '' && $out['language_rules'] === '' ? null : $out;
    }

    private function orNone(string $value): string
    {
        return trim($value) !== '' ? trim($value) : '(nothing yet)';
    }

    /** Render a reply list to the form's one-per-line textarea text. */
    private function lines(mixed $items): string
    {
        if (! is_array($items)) {
            return '';
        }

        return collect($items)
            ->map(fn ($v) => trim((string) (is_string($v) ? $v : '')))
            ->filter()
            ->implode("\n");
    }

    private function safeComplete(string $prompt, string $system): string
    {
        try {
            return $this->claude->complete($prompt, $system);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<string, mixed>|null */
    private function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/\{.*\}/s', $raw, $m) === 1) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
