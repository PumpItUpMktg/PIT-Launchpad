<?php

use App\ContentEngine\DuplicateGuard;
use App\Enums\ContentStatus;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use Tests\Support\TopicEmbeddings;

/** A live-or-in-flight post to collide against. */
function guardPost(Site $s, string $title, string $slug, ?string $siloId = null, string $status = 'published'): Content
{
    return Content::factory()->post()->create([
        'site_id' => $s->id, 'silo_id' => $siloId, 'title' => $title, 'slug' => $slug, 'status' => $status,
        'body' => '<p>x</p>',
    ]);
}

it('base-slug: catches a same-title re-report across a DIFFERENT silo (cross-silo by construction)', function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
    $site = Site::factory()->create();
    $siloA = Silo::factory()->create(['site_id' => $site->id]);
    $siloB = Silo::factory()->create(['site_id' => $site->id]);
    // A post's slug derives from its title, so the existing slug IS the slugified title.
    $title = 'Sewer Grants in Northern Chester County';
    $existing = guardPost($site, $title, 'sewer-grants-in-northern-chester-county', $siloA->id);

    // A re-report the scorer routes to a different silo — base-slug is site-wide, so it still catches it.
    $hit = app(DuplicateGuard::class)->duplicateOf($site, $title, $siloB->id);

    expect($hit)->toBe($existing->id);
});

it('base-slug: no collision → null (a genuinely new story proceeds)', function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    guardPost($site, 'Sump Pump Maintenance Tips', 'sump-pump-maintenance-tips', $silo->id);

    expect(app(DuplicateGuard::class)->duplicateOf($site, 'How to Read Your Water Meter', $silo->id))->toBeNull();
});

it('base-slug: a REJECTED twin does not block (the topic is open again)', function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    guardPost($site, 'Radon Test Tampering', 'radon-test-tampering', $silo->id, ContentStatus::Rejected->value);

    expect(app(DuplicateGuard::class)->baseSlugDuplicateOf($site, 'Radon Test Tampering'))->toBeNull();
});

it('base-slug: excludes the row itself via exceptId (re-checking an existing row)', function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
    $site = Site::factory()->create();
    $self = guardPost($site, 'Flooding Resources Hoboken', 'flooding-resources-hoboken');

    expect(app(DuplicateGuard::class)->baseSlugDuplicateOf($site, 'Flooding Resources Hoboken', $self->id))->toBeNull();
});

it('semantic ≥0.9: catches a DIFFERENT-title same-content post in the matched silo', function () {
    // A constant-vector embedder → every pair is cosine 1.0 → the detector's Refresh tier fires. This
    // isolates the semantic path: the titles differ (distinct base slugs), so only semantics can catch it.
    $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
    {
        public function embed(string $text): array
        {
            return [1.0, 0.0];
        }
    });
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $existing = guardPost($site, 'Preventing Basement Flooding', 'preventing-basement-flooding', $silo->id);

    $hit = app(DuplicateGuard::class)->duplicateOf($site, 'Keeping Your Cellar Dry This Spring', $silo->id, 'a body about sump pumps');

    expect($hit)->toBe($existing->id); // different base slug, caught semantically
});

it('semantic: no silo → the semantic pass is skipped (base-slug still applies)', function () {
    $this->app->instance(EmbeddingProvider::class, new class implements EmbeddingProvider
    {
        public function embed(string $text): array
        {
            return [1.0, 0.0];
        }
    });
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    guardPost($site, 'Preventing Basement Flooding', 'preventing-basement-flooding', $silo->id);

    // Different title (no base-slug hit) + null silo → nothing to compare semantically → null.
    expect(app(DuplicateGuard::class)->duplicateOf($site, 'Keeping Your Cellar Dry', null))->toBeNull();
});

it('title twin: a drafted title that means the same as a same-silo post is a HARD twin; a related-but-distinct title is a flag; another silo is not compared', function () {
    $this->app->instance(EmbeddingProvider::class, new TopicEmbeddings([
        'wildlife' => 'wildlife-maintenance',
        'backup systems before' => 'backup-before-floods',
    ]));
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $other = Silo::factory()->create(['site_id' => $site->id]);
    $live = guardPost($site, 'Sump Pump Maintenance Tips to Keep Wildlife and Water Out', 'sump-pump-maintenance-tips-to-keep-wildlife-and-water-out', $silo->id);
    guardPost($site, 'Sump Pump Maintenance: Keep Wildlife Out of Your Basement', 'sump-pump-maintenance-keep-wildlife-out-of-your-basement', $other->id);

    $guard = app(DuplicateGuard::class);

    // Same meaning (cosine 1.0) → hard twin, the live post named.
    $twin = $guard->titleTwin($site, 'Sump Pump Maintenance to Stop Wildlife and Water Damage', $silo->id);
    expect($twin)->not->toBeNull()
        ->and($twin['id'])->toBe($live->id)
        ->and($twin['hard'])->toBeTrue()
        ->and($twin['similarity'])->toBe(1.0);

    // Identical base slug is hard even when the embedding says "other" (a re-titled re-report).
    $twin = $guard->titleTwin($site, 'Sump pump maintenance tips to keep wildlife and water out', $silo->id);
    expect($twin['hard'])->toBeTrue();

    // A different topic in the same silo: only the word overlap speaks — below the flag tier → null.
    expect($guard->titleTwin($site, 'Sump Pump Maintenance and Backup Systems Before NJ Floods', $silo->id))->toBeNull();

    // The wildlife post in ANOTHER silo is never compared (silo-scoped, like the semantic pass).
    expect($guard->titleTwin($site, 'Keep Wildlife Out: A Sump Pump Guide', $other->id))->not->toBeNull()
        ->and($guard->titleTwin($site, 'Keep Wildlife Out: A Sump Pump Guide', $silo->id)['id'])->toBe($live->id);

    // The row itself is excluded on a re-check.
    expect($guard->titleTwin($site, $live->title, $silo->id, $live->id))->toBeNull();
});
