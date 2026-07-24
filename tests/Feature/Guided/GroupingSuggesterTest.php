<?php

use App\Enums\ServicePageTreatment;
use App\Guided\GroupingSuggester;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

function bindGroupingClaude(string $reply, bool $throw = false): void
{
    app()->instance(ClaudeClient::class, new class($reply, $throw) implements ClaudeClient
    {
        public function __construct(private string $reply, private bool $throw) {}

        public function complete(string $prompt, ?string $system = null): string
        {
            if ($this->throw) {
                throw new RuntimeException('boom');
            }

            return $this->reply;
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new BadMethodCallException('unused');
        }
    });
}

function grpService(Site $site, string $name): Service
{
    return Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => $name]);
}

it('nests the suggested sub-services under a parent, honoring page vs section', function () {
    $site = Site::factory()->create();
    $parent = grpService($site, 'Basement Waterproofing');
    $page = grpService($site, 'Sump Pump Installation');
    $section = grpService($site, 'Vapor Barrier');

    bindGroupingClaude(json_encode([[
        'parent' => 'Basement Waterproofing',
        'children' => [
            ['name' => 'Sump Pump Installation', 'treatment' => 'page'],
            ['name' => 'Vapor Barrier', 'treatment' => 'section'],
        ],
    ]]));

    $grouped = app(GroupingSuggester::class)->suggest($site);

    expect($grouped)->toBe(2)
        ->and($page->fresh()->parent_service_id)->toBe($parent->id)
        ->and($page->fresh()->page_treatment)->toBe(ServicePageTreatment::Page)
        ->and($section->fresh()->parent_service_id)->toBe($parent->id)
        ->and($section->fresh()->page_treatment)->toBe(ServicePageTreatment::Section)
        ->and($parent->fresh()->parent_service_id)->toBeNull();
});

it('never creates a third level — a suggested child that already has its own children is skipped', function () {
    $site = Site::factory()->create();
    $parent = grpService($site, 'Basement Waterproofing');
    $midHub = grpService($site, 'Crawl Space');
    // Crawl Space already parents a child → it can't itself become a sub-service.
    Service::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'name' => 'Encapsulation', 'parent_service_id' => $midHub->id,
        'page_treatment' => ServicePageTreatment::Section,
    ]);

    bindGroupingClaude(json_encode([[
        'parent' => 'Basement Waterproofing',
        'children' => [['name' => 'Crawl Space', 'treatment' => 'page']],
    ]]));

    app(GroupingSuggester::class)->suggest($site);

    expect($midHub->fresh()->parent_service_id)->toBeNull(); // stays top-level (2-level cap)
});

it('groups nothing on a Claude failure — never fatal', function () {
    $site = Site::factory()->create();
    grpService($site, 'A Service');
    grpService($site, 'Another Service');
    bindGroupingClaude('', throw: true);

    expect(app(GroupingSuggester::class)->suggest($site))->toBe(0);
});
