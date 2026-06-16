<?php

use App\Enums\UserRole;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Models\Content;
use App\Models\PageConfig;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('exposes the page config action', function () {
    Livewire::test(ListPages::class)->assertTableActionExists('pageConfig');
});

it('saves the user-owned page config', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id]);

    Livewire::test(ListPages::class)->callTableAction('pageConfig', $page, data: [
        'hero_variant' => 'form',
        'form_embed' => '<iframe src="https://ghl.example/form/xyz"></iframe>',
        'phone_override' => '+15551239999',
        'hero_image_override' => 'https://r2.example/op-hero.jpg',
        'market_ref' => 'Austin',
    ]);

    $config = PageConfig::query()->where('content_id', $page->id)->firstOrFail();
    expect($config->hero_variant)->toBe('form')
        ->and($config->site_id)->toBe($site->id)
        ->and($config->form_embed)->toContain('ghl.example')
        ->and($config->phone_override)->toBe('+15551239999')
        ->and($config->hero_image_override)->toBe('https://r2.example/op-hero.jpg')
        ->and($config->market_ref)->toBe('Austin');
});

it('updates the existing config in place (one row per page) and nulls blanks', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->page()->create(['site_id' => $site->id]);
    PageConfig::create(['site_id' => $site->id, 'content_id' => $page->id, 'hero_variant' => 'form', 'phone_override' => '+1000']);

    Livewire::test(ListPages::class)->callTableAction('pageConfig', $page, data: [
        'hero_variant' => 'cta',
        'form_embed' => '',
        'phone_override' => '',
        'hero_image_override' => '',
        'market_ref' => '',
    ]);

    expect(PageConfig::query()->where('content_id', $page->id)->count())->toBe(1);
    $config = PageConfig::query()->where('content_id', $page->id)->firstOrFail();
    expect($config->hero_variant)->toBe('cta')
        ->and($config->phone_override)->toBeNull(); // blank → null
});
