<?php

use App\ContentEngine\Questions\QuestionRefresher;
use App\Enums\ContentStatus;
use App\Enums\RefreshTrigger;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Site;
use Tests\Support\FakeClaudeClient;

function markedPost(Site $site, array $overrides = []): Content
{
    return Content::factory()->post()->create(array_merge([
        'site_id' => $site->id,
        'status' => ContentStatus::Published,
        'slug' => 'sump-pump-renovation-cost',
        'title' => 'Sump Pump Renovation Cost: A Complete Guide',
        'body' => '<p>Renovating a sump system is a project with several moving parts.</p><p>Most homeowners budget for the pit, the pump and the discharge line.</p>',
        'wp_post_id' => 4242,
        'version' => 3,
        'refresh_count' => 0,
        'slot_payload' => ['faq' => [['question' => 'Do you serve Bucks County?', 'answer' => 'Yes.']]],
        'meta' => [
            'seo' => ['title' => 'Sump Pump Renovation Cost Guide', 'meta_description' => 'A guide to renovation costs.'],
            'generating_at' => now()->toIso8601String(),
            'question_refresh' => ['query' => 'sump pump installation cost', 'phrasings' => ['how much does it cost to install a sump pump', 'cost to install sump pump'], 'impressions' => 373, 'clicks' => 0, 'position' => 58.6],
        ],
    ], $overrides));
}

function refresherWith(string $response): QuestionRefresher
{
    app()->instance(ClaudeClient::class, new FakeClaudeClient($response));

    return app(QuestionRefresher::class);
}

const GOOD_RESPONSE = '{"answer": "A professional sump pump installation typically runs from a few hundred dollars for a basic replacement to a few thousand for a new pit and discharge line. The pit, the pump grade and the discharge route decide the price.", "faq": [{"question": "Does a battery backup change the price?", "answer": "Yes — a backup adds its own pump and battery to the job."}, {"question": "sump pump installation cost", "answer": "repeat of the lead"}, {"question": "How long does installation take?", "answer": "Most installs finish in one visit."}], "title": "Sump Pump Installation Cost: What Homeowners Pay", "seo_title": "Sump Pump Installation Cost — What to Expect", "meta_description": "What a sump pump installation costs, what drives the price, and how to budget for it."}';

it('inserts the direct answer at the top, the Q&A at the end with FAQ schema, proposes the title, and moves the post into review', function () {
    $site = Site::factory()->create(['brand_name' => 'Acme Pumps']);
    $post = markedPost($site);

    $result = refresherWith(GOOD_RESPONSE)->refresh($post);
    $post->refresh();

    expect($post->status)->toBe(ContentStatus::NeedsReview)
        ->and($post->slug)->toBe('sump-pump-renovation-cost')          // the URL never changes
        ->and($post->wp_post_id)->toBe(4242)
        ->and((int) $post->version)->toBe(4)
        ->and((int) $post->refresh_count)->toBe(1)
        ->and($post->last_refreshed_at)->not->toBeNull()
        ->and($post->title)->toBe('Sump Pump Installation Cost: What Homeowners Pay')
        ->and($post->meta['seo']['title'])->toBe('Sump Pump Installation Cost — What to Expect')
        ->and($post->meta['seo']['meta_description'])->toContain('What a sump pump installation costs')
        ->and($post->meta)->not->toHaveKey('generating_at');

    // Body: answer block first, the original paragraphs untouched in the middle, the Q&A section last.
    expect($post->body)->toStartWith('<div class="lp-question-answer"><p><strong>Sump pump installation cost?</strong> A professional sump pump installation')
        ->and($post->body)->toContain('<p>Renovating a sump system is a project with several moving parts.</p><p>Most homeowners budget')
        ->and($post->body)->toEndWith('</section>')
        ->and($post->body)->toContain('<section class="lp-question-faq"><h2>Questions homeowners ask</h2><h3>Does a battery backup change the price?</h3>')
        ->and($post->body)->not->toContain('repeat of the lead');            // a pair repeating the lead question is dropped

    // FAQ slot: the pre-existing pair kept, the lane's two pairs appended → the plugin's FAQPage schema.
    expect(array_column($post->slot_payload['faq'], 'question'))->toBe(['Do you serve Bucks County?', 'Does a battery backup change the price?', 'How long does installation take?']);

    // The proposal keeps the old values beside the new for the reviewer.
    $proposal = $post->meta['question_refresh']['proposal'];
    expect($proposal['before']['title'])->toBe('Sump Pump Renovation Cost: A Complete Guide')
        ->and($proposal['after']['title'])->toBe('Sump Pump Installation Cost: What Homeowners Pay')
        ->and($proposal['changed'])->toBe(['title', 'seo_title', 'meta_description'])
        ->and($post->meta['question_refresh']['refreshed_at'])->not->toBeNull()
        ->and($result->faqCount)->toBe(2);

    $event = RefreshEvent::withoutGlobalScopes()->where('content_id', $post->id)->sole();
    expect($event->trigger)->toBe(RefreshTrigger::SearchQuestion);
});

it('re-runs idempotently — the two blocks are replaced, never stacked, and the lane\'s old FAQ pairs give way to the new', function () {
    $site = Site::factory()->create();
    $post = markedPost($site);
    $refresher = refresherWith(GOOD_RESPONSE);
    $refresher->refresh($post);
    $post->refresh();
    $post->forceFill(['status' => ContentStatus::Published])->save();

    $refresher->refresh($post);
    $post->refresh();

    expect(substr_count($post->body, 'lp-question-answer'))->toBe(1)
        ->and(substr_count($post->body, 'lp-question-faq'))->toBe(1)
        ->and(substr_count($post->body, 'Renovating a sump system'))->toBe(1)
        ->and(count($post->slot_payload['faq']))->toBe(3)
        ->and((int) $post->refresh_count)->toBe(2);
});

it('keeps the current title and SEO fields when the model returns blank or over-long ones', function () {
    $site = Site::factory()->create();
    $post = markedPost($site);
    $long = str_repeat('x', 200);

    refresherWith('{"answer": "Short answer here.", "faq": [], "title": "", "seo_title": "'.$long.'", "meta_description": ""}')->refresh($post);
    $post->refresh();

    expect($post->title)->toBe('Sump Pump Renovation Cost: A Complete Guide')
        ->and($post->meta['seo']['title'])->toBe('Sump Pump Renovation Cost Guide')
        ->and($post->meta['question_refresh']['proposal']['changed'])->toBe([])
        ->and($post->body)->not->toContain('lp-question-faq')
        ->and($post->slot_payload['faq'])->toHaveCount(1);
});

it('writes nothing when the response carries no answer, and refuses a page or an unmarked post', function () {
    $site = Site::factory()->create();
    $post = markedPost($site);

    expect(fn () => refresherWith('not json at all')->refresh($post))->toThrow(RuntimeException::class);
    expect(fn () => refresherWith('{"answer": "", "faq": []}')->refresh($post))->toThrow(RuntimeException::class);
    $post->refresh();
    expect($post->status)->toBe(ContentStatus::Published)->and((int) $post->version)->toBe(3)->and($post->body)->not->toContain('lp-question-answer');

    $unmarked = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'body' => '<p>x</p>', 'meta' => []]);
    expect(fn () => refresherWith(GOOD_RESPONSE)->refresh($unmarked))->toThrow(InvalidArgumentException::class);
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'meta' => ['question_refresh' => ['query' => 'q']]]);
    expect(fn () => refresherWith(GOOD_RESPONSE)->refresh($page))->toThrow(InvalidArgumentException::class);
});
