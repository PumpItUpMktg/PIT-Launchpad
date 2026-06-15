<?php

use App\Branding\BrandBrief;
use App\Branding\BrandGenerator;
use App\Branding\ContrastMatrix;
use App\Branding\FontCatalog;
use Tests\Support\FakeClaudeClient;

function generator(string $json): BrandGenerator
{
    return new BrandGenerator(new FakeClaudeClient($json), new FontCatalog);
}

function candidate(array $over = []): array
{
    return array_merge([
        'tokens' => [
            '--wf-color-primary' => '#1B3A5B',
            '--wf-color-secondary' => '#3E6E9E',
            '--wf-color-accent' => '#B25C00', // dark amber — white CTA text passes AA
            '--wf-color-text' => '#1A1A1A',
            '--wf-color-text-muted' => '#5B6470',
            '--wf-color-bg' => '#FFFFFF',
            '--wf-color-bg-alt' => '#F4F6F8',
            '--wf-color-border' => '#E2E6EB',
        ],
        'fonts' => ['--wf-font-heading' => 'Archivo', '--wf-font-body' => 'Inter'],
        'rationale' => 'Deep navy reads dependable for plumbing; the amber CTA adds urgency.',
        'recommended' => false,
    ], $over);
}

function brief(): BrandBrief
{
    return new BrandBrief(industry: 'plumbing', personality: 'trustworthy');
}

it('recommends the model structure when valid', function () {
    $rec = generator(json_encode(['structure' => 'bold', 'rationale' => 'Conversion-forward.']))
        ->recommendStructure(brief());

    expect($rec->slug)->toBe('bold')->and($rec->rationale)->toBe('Conversion-forward.');
});

it('falls back to the personality→structure map for an off-list structure', function () {
    $rec = generator(json_encode(['structure' => 'spaceship']))
        ->recommendStructure(new BrandBrief(industry: 'hvac', personality: 'friendly-local'));

    expect($rec->slug)->toBe('warm'); // friendly-local → warm (deterministic enforcer)
});

it('generates validated candidates with the full token set and exactly one recommended', function () {
    $json = json_encode(['candidates' => [
        candidate(),
        candidate(['recommended' => true, 'tokens' => candidate()['tokens'] + []]),
        candidate(),
    ]]);

    $set = generator($json)->generateCandidates(brief(), 'trust', 3);

    expect($set->candidates)->toHaveCount(3)
        ->and($set->structure)->toBe('trust')
        ->and($set->recommended())->not->toBeNull()
        ->and(collect($set->candidates)->where('recommended', true))->toHaveCount(1)
        ->and($set->candidates[1]->recommended)->toBeTrue(); // the model's pick survived

    $rec = $set->recommended();
    expect($rec->palette)->toHaveKeys(['primary', 'secondary', 'accent', 'on_accent', 'text', 'text_muted', 'bg', 'bg_alt', 'border'])
        ->and($rec->palette['primary'])->toBe('#1b3a5b')
        ->and($rec->palette['on_accent'])->toBe('#ffffff')  // #b25c00 is dark → white CTA text
        ->and($rec->typography)->toBe(['heading' => 'Archivo', 'body' => 'Inter']);
});

it('falls back an invented font to the safe default and flags it', function () {
    $set = generator(json_encode(['candidates' => [
        candidate(['fonts' => ['--wf-font-heading' => 'Nonexistent Sans Ultra', '--wf-font-body' => 'Inter']]),
    ]]))->generateCandidates(brief(), 'trust', 1);

    $c = $set->candidates[0];
    expect($c->typography['heading'])->toBe('Poppins')
        ->and($c->typography['body'])->toBe('Inter')
        ->and(collect($c->adjustments)->contains(fn ($a) => str_contains($a, 'not a loadable Google Font')))->toBeTrue();
});

it('RESCUES a light accent with dark CTA text instead of dropping it', function () {
    // Bright yellow accent: white CTA text fails, but dark CTA text passes — so the
    // candidate survives with on_accent = dark (the old all-drop regression).
    $set = generator(json_encode(['candidates' => [
        candidate(['tokens' => array_merge(candidate()['tokens'], ['--wf-color-accent' => '#FFEE33'])]),
    ]]))->generateCandidates(brief(), 'bold', 1);

    $c = $set->candidates[0];
    expect($c->palette['accent'])->toBe('#ffee33')
        ->and($c->palette['on_accent'])->toBe('#1a1a1a')                 // dark CTA text, not white
        ->and(collect($c->adjustments)->contains(fn ($a) => str_contains($a, 'safe default')))->toBeFalse(); // NOT the fallback
});

it('DROPS only a genuine mid-tone accent (neither white nor dark passes) → structure-matched safe default', function () {
    // #7c7c7c mid-gray: white-on it ~4.2 and dark-on it ~4.1 — both below AA, so it is
    // genuinely un-rescuable and the candidate is dropped.
    $set = generator(json_encode(['candidates' => [
        candidate(['tokens' => array_merge(candidate()['tokens'], ['--wf-color-accent' => '#7C7C7C'])]),
    ]]))->generateCandidates(brief(), 'bold', 1);

    $safe = $set->recommended();
    expect($safe->adjustments)->toContain('All generated candidates were dropped by the contrast gate; using the safe default.')
        ->and($safe->typography)->toBe(['heading' => 'Sora', 'body' => 'Inter'])  // bold's first pairing, not Poppins
        ->and(ContrastMatrix::failures($safe->palette))->toBe([]);    // the fallback itself passes
});

it('auto-nudges unreadable body text to a safe neutral and flags it (accent still valid)', function () {
    $set = generator(json_encode(['candidates' => [
        candidate(['tokens' => array_merge(candidate()['tokens'], ['--wf-color-text' => '#CCCCCC'])]), // fails on white bg
    ]]))->generateCandidates(brief(), 'trust', 1);

    $c = $set->candidates[0];
    expect($c->palette['text'])->toBe('#1a1a1a') // corrected to safe neutral
        ->and(collect($c->adjustments)->contains(fn ($a) => str_contains($a, 'failed WCAG-AA')))->toBeTrue();
});

it('steers the prompt to the chosen structure\'s curated font pairings', function () {
    $fake = new FakeClaudeClient(json_encode(['candidates' => [candidate()]]));
    (new BrandGenerator($fake, new FontCatalog))->generateCandidates(brief(), 'warm', 1);

    // The warm pairings (operator-redlined) reach the model; trust's do not.
    expect($fake->prompts[0])->toContain('Fraunces / Source Sans 3')
        ->toContain('Nunito Sans / Nunito Sans')
        ->not->toContain('Space Grotesk'); // a bold-only pairing
});

it('ContrastMatrix flags the failing pairs and clears a clean palette', function () {
    expect(ContrastMatrix::failures([
        'text' => '#1a1a1a', 'text_muted' => '#5b6470', 'bg' => '#ffffff', 'bg_alt' => '#f4f6f8', 'accent' => '#b25c00',
    ]))->toBe([]);

    // A light accent no longer fails the button pair (on-accent picks dark text); the
    // text-on-bg failure still surfaces. A mid-tone accent DOES fail the button pair.
    $light = collect(ContrastMatrix::failures(['text' => '#cccccc', 'text_muted' => '#dddddd', 'bg' => '#ffffff', 'bg_alt' => '#ffffff', 'accent' => '#ffee33']))->pluck('pair');
    expect($light)->toContain('text-on-bg')->not->toContain('button-text-on-accent');

    $midTone = collect(ContrastMatrix::failures(['text' => '#1a1a1a', 'text_muted' => '#5b6470', 'bg' => '#ffffff', 'bg_alt' => '#f4f6f8', 'accent' => '#7c7c7c']))->pluck('pair');
    expect($midTone)->toContain('button-text-on-accent');
});

it('ContrastMatrix::onAccent picks dark text for a light accent and white for a dark accent', function () {
    expect(ContrastMatrix::onAccent('#ffee33'))->toBe('#1a1a1a')  // light accent → dark CTA text
        ->and(ContrastMatrix::onAccent('#1b3a5b'))->toBe('#ffffff'); // dark accent → white CTA text
});
