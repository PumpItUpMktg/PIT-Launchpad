<?php

use App\Branding\BrandBrief;
use App\Branding\BrandGenerator;
use App\Branding\FontCatalog;
use Tests\Support\FakeClaudeClient;

function generateBrand(string $json, ?BrandBrief $brief = null): array
{
    $generator = new BrandGenerator(new FakeClaudeClient($json), new FontCatalog);
    $brand = $generator->generate($brief ?? new BrandBrief(industry: 'plumbing', personality: 'trustworthy'));

    return [$brand, $generator];
}

it('parses a valid generated brand and keeps real fonts + colors', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'Blue conveys reliability; Montserrat is confident and modern.',
    ]));

    expect($brand->palette)->toBe(['primary' => '#0f62fe', 'accent' => '#ff6f00', 'text' => '#1a1a1a'])
        ->and($brand->typography)->toBe(['heading' => 'Montserrat', 'body' => 'Inter'])
        ->and($brand->rationale)->toContain('reliability')
        ->and($brand->adjustments)->toBe([]);
});

it('falls back to a safe default when the model invents a font (the silent-cascade-break guard)', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Helvetica Neue Ultra', 'body' => 'Inter'],
        'rationale' => 'x',
    ]));

    expect($brand->typography['heading'])->toBe('Poppins')       // safe default
        ->and($brand->typography['body'])->toBe('Inter')          // real → kept
        ->and($brand->adjustments)->toHaveCount(1)
        ->and($brand->adjustments[0])->toContain('not a loadable Google Font');
});

it('normalizes a real but loosely-spelled font to its canonical name', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'playfair display', 'body' => 'inter'],
        'rationale' => 'x',
    ]));

    expect($brand->typography)->toBe(['heading' => 'Playfair Display', 'body' => 'Inter'])
        ->and($brand->adjustments)->toBe([]); // canonicalization is not an "adjustment"
});

it('falls back on an invalid hex color', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => 'not-a-color', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'x',
    ]));

    expect($brand->palette['primary'])->toBe('#0f62fe') // safe default
        ->and($brand->adjustments)->toContain('Invalid or missing primary color — fell back to #0F62FE.');
});

it('expands and normalizes a 3-digit hex', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => '#06F', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'x',
    ]));

    expect($brand->palette['primary'])->toBe('#0066ff')
        ->and($brand->adjustments)->toBe([]);
});

it('corrects a text color that fails WCAG-AA on a light background', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#EEEEEE'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'x',
    ]));

    expect($brand->palette['text'])->toBe('#1a1a1a')
        ->and(collect($brand->adjustments)->contains(fn ($a) => str_contains($a, 'WCAG-AA')))->toBeTrue();
});

it('tolerates code fences / prose around the JSON', function () {
    [$brand] = generateBrand("Here is the brand:\n```json\n".json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'ok',
    ])."\n```\nHope that helps!");

    expect($brand->palette['primary'])->toBe('#0f62fe')
        ->and($brand->rationale)->toBe('ok');
});

it('round-trips to the SiteBranding shape the assembler consumes', function () {
    [$brand] = generateBrand(json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'x',
    ]));

    expect($brand->toBranding())->toBe([
        'palette' => ['primary' => '#0f62fe', 'accent' => '#ff6f00', 'text' => '#1a1a1a'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
    ]);
});

it('feeds the interview brief into the prompt (industry + personality + anchors)', function () {
    $fake = new FakeClaudeClient(json_encode([
        'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00', 'text' => '#1A1A1A'],
        'typography' => ['heading' => 'Montserrat', 'body' => 'Inter'],
        'rationale' => 'x',
    ]));

    (new BrandGenerator($fake, new FontCatalog))->generate(new BrandBrief(
        industry: 'roofing',
        personality: 'bold-urgent',
        emotionalGoal: 'safe and protected',
        colorAnchorsAvoid: ['pink'],
        admiredBrand: 'Stripe',
    ));

    $prompt = $fake->prompts[0];

    expect($prompt)->toContain('roofing')
        ->and($prompt)->toContain('Bold & urgent')          // curated personality label
        ->and($prompt)->toContain('safe and protected')
        ->and($prompt)->toContain('Avoid these colors: pink')
        ->and($prompt)->toContain('Stripe')
        ->and($prompt)->toContain('WCAG-AA')                 // the enforced color rule
        ->and($prompt)->toContain('Google Fonts');          // the enforced font rule
});
