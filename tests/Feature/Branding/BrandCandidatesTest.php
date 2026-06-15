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
    expect($rec->palette)->toHaveKeys(['primary', 'secondary', 'accent', 'text', 'text_muted', 'bg', 'bg_alt', 'border'])
        ->and($rec->palette['primary'])->toBe('#1b3a5b')
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

it('DROPS a candidate whose accent cannot carry white CTA text (the conversion gate)', function () {
    // Bright yellow accent: white button text fails AA → the only candidate is dropped
    // → the set degrades to the synthesized safe candidate (never empty).
    $set = generator(json_encode(['candidates' => [
        candidate(['tokens' => array_merge(candidate()['tokens'], ['--wf-color-accent' => '#FFEE33'])]),
    ]]))->generateCandidates(brief(), 'bold', 1);

    expect($set->candidates)->toHaveCount(1)
        ->and($set->recommended()->adjustments)
        ->toContain('All generated candidates were dropped by the contrast gate; using the safe default.');
});

it('auto-nudges unreadable body text to a safe neutral and flags it (accent still valid)', function () {
    $set = generator(json_encode(['candidates' => [
        candidate(['tokens' => array_merge(candidate()['tokens'], ['--wf-color-text' => '#CCCCCC'])]), // fails on white bg
    ]]))->generateCandidates(brief(), 'trust', 1);

    $c = $set->candidates[0];
    expect($c->palette['text'])->toBe('#1a1a1a') // corrected to safe neutral
        ->and(collect($c->adjustments)->contains(fn ($a) => str_contains($a, 'failed WCAG-AA')))->toBeTrue();
});

it('ContrastMatrix flags the failing pairs and clears a clean palette', function () {
    expect(ContrastMatrix::failures([
        'text' => '#1a1a1a', 'text_muted' => '#5b6470', 'bg' => '#ffffff', 'bg_alt' => '#f4f6f8', 'accent' => '#b25c00',
    ]))->toBe([]);

    $bad = ContrastMatrix::failures(['text' => '#cccccc', 'text_muted' => '#dddddd', 'bg' => '#ffffff', 'bg_alt' => '#ffffff', 'accent' => '#ffee33']);
    expect(collect($bad)->pluck('pair'))->toContain('text-on-bg', 'button-text-on-accent');
});
