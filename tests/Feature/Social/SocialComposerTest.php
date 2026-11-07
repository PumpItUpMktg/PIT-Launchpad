<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialTrigger;
use App\Models\Content;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobTypeAssignment;
use App\Models\Silo;
use App\Models\Site;
use App\Social\Hashtags;
use App\Social\SocialComposer;

function socialSite(): Site
{
    return Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);
}

function socialJob(Site $site, array $overrides = []): Job
{
    $city = JobCity::factory()->create(['name' => 'Hackettstown', 'state' => 'NJ']);
    $job = Job::factory()->create(array_merge([
        'site_id' => $site->id, 'status' => JobStatus::Published, 'job_city_id' => $city->id,
        'post_title' => 'Sump Pump Replacement in Hackettstown',
        'enhanced_description' => '<p>We replaced a failed pedestal pump with a sealed submersible unit. The pit was cleaned and a new check valve fitted.</p><p>The homeowner now has a quiet pump with a battery backup ready for the next storm.</p>',
        'photos' => [['r2_key' => 'tenants/x/jobs/1.jpg']],
        'wp_post_id' => 12,
    ], $overrides));
    JobTypeAssignment::create(['job_capture_id' => $job->id, 'label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']);

    return $job->fresh(['site', 'city', 'jobTypes']);
}

function socialPost(Site $site, array $meta = [], string $slug = 'sump-pump-installation-cost'): Content
{
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);

    return Content::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'title' => 'Sump Pump Installation Cost', 'slug' => $slug, 'wp_post_id' => 5,
        'body' => '<p>Installing a sump pump costs between $600 and $2,000 in most homes.</p><p>Battery backups add more.</p>',
        'meta' => array_merge(['seo' => ['meta_description' => 'What a sump pump installation costs, and what moves the price.']], $meta),
    ])->fresh(['site', 'silo', 'renderJobs']);
}

it('composes a GBP job share — service and town in the words, no hashtags, a tagged Learn-more link, under the GBP cap', function () {
    $job = socialJob(socialSite());

    $copy = app(SocialComposer::class)->job($job, SocialNetwork::Gbp, SocialTrigger::JobPublished);

    expect($copy->copy)->toStartWith('Sump Pump Replacement in Hackettstown')
        ->toContain('We replaced a failed pedestal pump')
        ->toContain('Sump Pump Gurus serving Hackettstown, NJ.')
        ->toContain('See the job: https://spg.example/jobs/')
        ->toContain('utm_source=gbp')->toContain('utm_medium=social')->toContain('utm_content=job_published')
        ->not->toContain('#')
        ->and(mb_strlen($copy->copy))->toBeLessThanOrEqual(1500)
        ->and($copy->hashtags)->toBe([])
        ->and($copy->cta)->toBe('LEARN_MORE')
        ->and($copy->imageUrl)->toContain('tenants/x/jobs/1.jpg');
});

it('a Facebook job share carries the service + town hashtags; Instagram keeps the link out of the caption', function () {
    $job = socialJob(socialSite());
    $composer = app(SocialComposer::class);

    $fb = $composer->job($job, SocialNetwork::Facebook, SocialTrigger::JobPublished);
    $ig = $composer->job($job, SocialNetwork::Instagram, SocialTrigger::JobPublished);

    expect($fb->hashtags)->toBe(['#SumpPumpRepair', '#HackettstownNJ'])
        ->and($fb->copy)->toContain('#SumpPumpRepair #HackettstownNJ')->toContain('utm_source=facebook')
        ->and($ig->copy)->not->toContain('https://')->toContain('#HackettstownNJ')
        ->and($ig->link)->toContain('utm_source=instagram');
});

it('a question post leads with the question, then the direct answer, then the title — the link is tagged with the slug', function () {
    $post = socialPost(socialSite(), ['question_refresh' => ['question' => 'how much does a sump pump cost to install', 'answer' => 'Most homes pay $600 to $2,000 installed; a battery backup adds $300 to $900.', 'refreshed_at' => now()->toIso8601String()]]);

    $copy = app(SocialComposer::class)->post($post, SocialNetwork::Facebook, SocialTrigger::PostRefreshed);

    expect($copy->copy)->toStartWith("How much does a sump pump cost to install?\n\nMost homes pay $600 to $2,000 installed")
        ->toContain('Full answer: Sump Pump Installation Cost')
        ->toContain('Read the answer: https://spg.example/sump-pump-installation-cost/?utm_source=facebook')
        ->toContain('utm_campaign=sump-pump-installation-cost')->toContain('utm_content=post_refreshed')
        ->and($copy->hashtags)->toBe(['#SumpPumps', '#SumpPumpGurus']);
});

it('a plain post uses its title and meta description', function () {
    $post = socialPost(socialSite());

    $copy = app(SocialComposer::class)->post($post, SocialNetwork::Gbp, SocialTrigger::PostPublished);

    expect($copy->copy)->toStartWith("Sump Pump Installation Cost\n\nWhat a sump pump installation costs")
        ->and($copy->hashtags)->toBe([]);
});

it('cuts long copy at a sentence inside the network cap and the link always survives', function () {
    $long = str_repeat('This sentence keeps the write-up going for a while. ', 60);   // ~3,000 chars
    $job = socialJob(socialSite(), ['enhanced_description' => $long]);
    config(['launchpad.social.excerpt_chars' => 2000]);

    $copy = app(SocialComposer::class)->job($job, SocialNetwork::Gbp, SocialTrigger::JobPublished);

    expect(mb_strlen($copy->copy))->toBeLessThanOrEqual(1500)
        ->and($copy->copy)->toContain('See the job: https://spg.example/jobs/')
        ->and(str_ends_with(explode("\n\nSee the job", $copy->copy)[0], '.'))->toBeTrue();
});

it('builds the GBP Q&A pair for a question post, and none for a post without an answer', function () {
    $site = socialSite();
    $with = socialPost($site, ['question_refresh' => ['question' => 'why does my sump pump run constantly', 'answer' => 'A stuck float or a high water table keeps it running; check the float first.', 'refreshed_at' => now()->toIso8601String()]]);
    $without = socialPost($site, [], 'plain-post');

    $qa = app(SocialComposer::class)->gbpQa($with);

    expect($qa['question'])->toBe('Why does my sump pump run constantly?')
        ->and($qa['answer'])->toStartWith('A stuck float or a high water table')->toContain('More: https://spg.example/sump-pump-installation-cost/?utm_source=gbp')->toContain('utm_content=qa')
        ->and(app(SocialComposer::class)->gbpQa($without))->toBeNull();
});

it('hashtags: StudlyCase, short tokens upper-cased, numbers alone dropped', function () {
    expect(Hashtags::one('sump pump repair'))->toBe('#SumpPumpRepair')
        ->and(Hashtags::town('Mt. Olive', 'nj'))->toBe('#MtOliveNJ')
        ->and(Hashtags::one('2024'))->toBeNull()
        ->and(Hashtags::one('NJ pumps'))->toBe('#NjPumps')
        ->and(Hashtags::from(['Sump Pumps', 'sump pumps', null]))->toBe(['#SumpPumps']);
});
