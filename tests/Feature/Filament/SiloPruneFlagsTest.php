<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\SiloPrune;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Interview\Arrange\AutoArrangeRunner;
use App\Models\ArrangementFlag;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Self-contained 3-silo magnet fake (Backup Power + Pump Protection cluster into Sump Pumps). */
class SiloPruneFlagsFake implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'battery backup sump pump') => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            str_contains($t, 'battery') => [0.8, 0.0, 0.0, 0.0, 0.0, 0.6],
            str_contains($t, 'sump pit') => [0.0, 1.0, 0.0, 0.0, 0.0, 0.0],
            str_contains($t, 'monitoring'), str_contains($t, 'protection plan') => [0.0, 0.8, 0.0, 0.0, 0.0, 0.6],
            str_contains($t, 'sump pumps') => [0.0, 0.0, 1.0, 0.0, 0.0, 0.0],
            str_contains($t, 'backup power') => [0.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            str_contains($t, 'pump protection') => [0.0, 0.0, 0.0, 0.0, 1.0, 0.0],
            default => [0.0, 0.0, 0.0, 0.0, 0.0, 1.0],
        };
    }
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(EmbeddingProvider::class, new SiloPruneFlagsFake);

    $this->site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    $make = fn (array $a) => Spoke::factory()->create(array_merge([
        'site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'status' => SpokeStatus::Candidate,
        'page_type' => SpokePageType::Service, 'tag' => SpokeTag::Core, 'head_keyword' => '', 'granularity' => SpokeGranularity::OwnPage,
    ], $a));

    $make(['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    $make(['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'head_keyword' => 'battery backup', 'volume' => 300]);
    $make(['silo' => 'Sump Pumps', 'name' => 'Sump Pit Basin', 'head_keyword' => 'sump pit', 'volume' => 150]);
    $make(['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    $make(['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'head_keyword' => 'battery backup system', 'volume' => 40, 'tag' => SpokeTag::Adjacent, 'granularity' => SpokeGranularity::Folded]);
    $make(['silo' => 'Pump Protection', 'name' => 'Pump Protection', 'is_pillar' => true]);
    $make(['silo' => 'Pump Protection', 'name' => 'Pump Protection Plan', 'head_keyword' => 'protection plan', 'volume' => 25, 'tag' => SpokeTag::Connecting, 'granularity' => SpokeGranularity::Folded]);
});

test('the prune page runs auto-arrange and lists the flags', function () {
    $page = Livewire::test(SiloPrune::class)
        ->set('siteId', $this->site->id)
        ->call('runAutoArrange');

    expect($page->instance()->arrangeFlags)->not->toBeEmpty()
        ->and(ArrangementFlag::query()->where('site_id', $this->site->id)->count())->toBe(2);
});

test('dismissing a flag from the page removes it', function () {
    app(AutoArrangeRunner::class)->run($this->site);
    $flagId = ArrangementFlag::query()->where('site_id', $this->site->id)->value('id');

    Livewire::test(SiloPrune::class)
        ->set('siteId', $this->site->id)
        ->call('dismissFlag', $flagId);

    expect(ArrangementFlag::query()->whereKey($flagId)->exists())->toBeFalse();
});
