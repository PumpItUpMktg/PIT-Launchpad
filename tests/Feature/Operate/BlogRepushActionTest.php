<?php

use App\Enums\ContentKind;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

it('bulk re-pushes the active tenant\'s published posts from the Blog board', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    foreach (range(1, 3) as $n) {
        Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post->value, 'status' => 'published', 'wp_post_id' => $n, 'slug' => 'p'.$n]);
    }

    Livewire::test(OperateBlog::class)
        ->set('siteFilter', $site->id)
        ->call('repushAllPublished')
        ->assertNotified('Re-pushing 3 published post(s)');

    Queue::assertPushed(PublishContent::class, 3);
});

it('warns when no tenant is selected', function () {
    Queue::fake();

    Livewire::test(OperateBlog::class)
        ->set('siteFilter', null)
        ->call('repushAllPublished')
        ->assertNotified('Pick a tenant first');

    Queue::assertNothingPushed();
});
