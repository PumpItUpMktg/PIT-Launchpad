<?php

use App\ContentEngine\Questions\QuestionCluster;
use App\ContentEngine\Questions\QuestionIntake;
use App\ContentEngine\Questions\QuestionMiningReport;
use App\ContentEngine\Questions\QuestionStat;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\IntakeType;
use App\Enums\PageType;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Operate\BlogBoard;

beforeEach(function () {
    $this->app->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
});

function questionReport(Site $site, array $clusters): QuestionMiningReport
{
    return new QuestionMiningReport((string) $site->id, 90, '2026-08-03', '2026-11-01', 500, count($clusters), [], $clusters);
}

function questionTopic(string $query, int $impressions, ?string $url = null, ?float $position = null, array $others = []): QuestionCluster
{
    $urls = $url !== null ? [$url => ['impressions' => $impressions, 'clicks' => 3, 'position' => $position]] : [];
    $lead = new QuestionStat($query, $impressions, 3, $position, $position, 12, '2026-08-10', '2026-10-30', 9, $urls);
    $variants = [$lead];
    foreach ($others as $o) {
        $variants[] = new QuestionStat($o, 10, 1, $position, $position, 3, '2026-09-01', '2026-10-30', 4, $urls === [] ? [] : [$url => ['impressions' => 10, 'clicks' => 1, 'position' => $position]]);
    }

    return new QuestionCluster($lead, $variants);
}

function pumpSilo(Site $site): Silo
{
    return Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'rule_set' => ['include_patterns' => ['sump pump'], 'seed_terms' => [], 'exclude_patterns' => []]]);
}

it('plans then files a questions candidate pinned to the silo the bucketer routes it to, with the demand on it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $silo = pumpSilo($site);
    $report = questionReport($site, [questionTopic('how long do sump pumps last', 140, 'https://acme.test/', 9.0, ['how long does a sump pump last'])]);

    $intake = app(QuestionIntake::class);
    $plan = $intake->plan($site, $report, 15);
    expect($plan)->toHaveCount(1)->and($plan[0]['action'])->toBe(QuestionIntake::ACTION_FILE)->and($plan[0]['silo']?->id)->toBe($silo->id)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0); // plan writes nothing

    $done = $intake->apply($site, $report, $plan);
    $c = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();

    expect($done)->toBe(['filed' => 1, 'marked' => 0, 'posts_marked' => 0, 'refreshed' => 0])
        ->and($c->kind)->toBe(ContentKind::Post)
        ->and($c->status)->toBe(ContentStatus::Candidate)
        ->and($c->source_name)->toBe('questions')
        ->and($c->draft_trigger)->toBe(DraftTrigger::Question)
        ->and($c->intake_type)->toBe(IntakeType::Directed)
        ->and($c->silo_id)->toBe($silo->id)->and($c->matched_silo_id)->toBe($silo->id)
        ->and($c->title)->toBe('How long do sump pumps last?')
        ->and($c->slug)->toBe('how-long-do-sump-pumps-last')
        ->and($c->angle_hint)->toContain('“how long do sump pumps last”')->toContain('“how long does a sump pump last”')
        ->and($c->meta['shelf_life'])->toBe('evergreen')
        ->and($c->meta['question']['query'])->toBe('how long do sump pumps last')
        ->and($c->meta['question']['phrasings'])->toBe(['how long does a sump pump last'])
        ->and($c->meta['question']['impressions'])->toBe(150)
        ->and((float) $c->meta['question']['position'])->toBe(9.0)
        ->and($c->meta['question']['url'])->toBe('https://acme.test/')
        ->and($c->meta['question']['window_from'])->toBe('2026-08-03');

    // The board shows it cap-exempt with its demand.
    $rows = app(BlogBoard::class)->candidates($site->id);
    expect($rows[0]['question']['impressions'])->toBe(150)->and($rows[0]['source'])->toBe('questions');
    $groups = app(BlogBoard::class)->group($rows, 0);
    expect($groups[0]['visible'][0]['id'])->toBe((string) $c->id);
});

it('re-runs idempotently: a standing candidate is re-stamped, never duplicated; a dismissed one is never re-filed', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    pumpSilo($site);
    $intake = app(QuestionIntake::class);

    $first = questionReport($site, [questionTopic('how long do sump pumps last', 140, 'https://acme.test/', 9.0)]);
    $intake->apply($site, $first, $intake->plan($site, $first, 15));

    // Next month: a sibling phrasing leads the cluster, with fresh numbers.
    $second = questionReport($site, [questionTopic('how long does a sump pump last', 210, 'https://acme.test/', 7.0, ['how long do sump pumps last'])]);
    $plan = $intake->plan($site, $second, 15);
    expect($plan[0]['action'])->toBe(QuestionIntake::ACTION_ALREADY);
    $done = $intake->apply($site, $second, $plan);
    $rows = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($done['refreshed'])->toBe(1)
        ->and($rows[0]->meta['question']['impressions'])->toBe(220);

    // Operator dismisses it: never re-filed.
    $rows[0]->forceFill(['status' => ContentStatus::Rejected])->save();
    $plan = $intake->plan($site, $second, 15);
    expect($plan[0]['action'])->toBe(QuestionIntake::ACTION_DISMISSED);
    $intake->apply($site, $second, $plan);
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('marks the post already answering a question for a refresh instead of filing a second one, and lists it on the board', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $silo = pumpSilo($site);
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'matched_silo_id' => $silo->id, 'slug' => 'sump-pump-lifespan', 'title' => 'Sump pump lifespan', 'status' => ContentStatus::Published, 'body' => 'x']);
    $report = questionReport($site, [questionTopic('how long do sump pumps last', 140, 'https://acme.test/sump-pump-lifespan/', 14.0)]);

    $intake = app(QuestionIntake::class);
    $plan = $intake->plan($site, $report, 15);
    expect($plan[0]['action'])->toBe(QuestionIntake::ACTION_MARK)->and($plan[0]['existing']?->id)->toBe($post->id);

    $done = $intake->apply($site, $report, $plan);
    $mark = $post->fresh()->meta['question_refresh'];
    expect($done['marked'])->toBe(1)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1)
        ->and($mark['query'])->toBe('how long do sump pumps last')
        ->and((float) $mark['position'])->toBe(14.0)
        ->and($mark['reason'])->toContain('refresh it');

    $marks = app(BlogBoard::class)->questionRefreshMarks($site->id);
    expect($marks)->toHaveCount(1)
        ->and($marks[0]['id'])->toBe((string) $post->id)
        ->and($marks[0]['url'])->toBe('https://acme.test/sump-pump-lifespan')
        ->and($marks[0]['impressions'])->toBe(140);
});

it('lists a covered topic and an unrouted one without filing, and spends the cap only on real asks', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    pumpSilo($site);
    Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'sump-pump-lifespan', 'title' => 'Sump pump lifespan', 'status' => ContentStatus::Published, 'body' => 'x']);
    $report = questionReport($site, [
        questionTopic('how long do sump pumps last', 300, 'https://acme.test/sump-pump-lifespan/', 2.0),  // covered: not counted
        questionTopic('why is my basement humid', 200, 'https://acme.test/', 20.0),                       // unrouted: no silo claims it
        questionTopic('how often should a sump pump run', 150, 'https://acme.test/', 15.0),               // file (1)
        questionTopic('does a sump pump need a dedicated circuit', 120, null, null),                      // file (2)
        questionTopic('what size sump pump do i need', 90, null, null),                                    // beyond the cap of 2
    ]);

    $plan = app(QuestionIntake::class)->plan($site, $report, 2);
    $actions = array_map(fn (array $r): string => $r['action'], $plan);

    expect($actions)->toBe([QuestionIntake::ACTION_COVERED, QuestionIntake::ACTION_UNROUTED, QuestionIntake::ACTION_FILE, QuestionIntake::ACTION_FILE]);

    app(QuestionIntake::class)->apply($site, $report, $plan);
    expect(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source_name', 'questions')->count())->toBe(2);
});

it('titles a question with a question mark and leaves an intent query as typed', function () {
    expect(QuestionIntake::titleFor('how long do sump pumps last'))->toBe('How long do sump pumps last?')
        ->and(QuestionIntake::titleFor('how long do sump pumps last?'))->toBe('How long do sump pumps last?')
        ->and(QuestionIntake::titleFor('sump pump replacement cost'))->toBe('Sump pump replacement cost');
});

it('routes a rule-set-silent question through the silo of the page already ranking for it, and lists an orphaned topic without filing', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Backup Systems', 'rule_set' => ['include_patterns' => ['battery backup'], 'seed_terms' => [], 'exclude_patterns' => []]]);
    Content::factory()->page()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'page_type' => PageType::Service, 'slug' => 'backup-sump-pump-solutions/water-powered-backup-sump-pumps', 'title' => 'Water-powered backup sump pumps', 'status' => ContentStatus::Published]);
    $retired = Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'sump-pump-installation-cost-breakdown-10', 'title' => 'Sump pump installation cost breakdown', 'status' => ContentStatus::Published, 'body' => 'x']);
    $retired->delete();

    $report = questionReport($site, [
        questionTopic('sump pump installation cost', 373, 'https://acme.test/sump-pump-installation-cost-breakdown-10/', 58.6),
        questionTopic('how do water powered sump pumps work', 51, 'https://acme.test/backup-sump-pump-solutions/water-powered-backup-sump-pumps/', 32.6),
    ]);

    $intake = app(QuestionIntake::class);
    $plan = $intake->plan($site, $report, 15);

    expect($plan[0]['action'])->toBe(QuestionIntake::ACTION_ORPHANED)->and($plan[0]['existing']?->id)->toBe($retired->id)
        ->and($plan[1]['action'])->toBe(QuestionIntake::ACTION_FILE)->and($plan[1]['silo']?->id)->toBe($silo->id)->and($plan[1]['routed_via'])->toBe('ranking_page');

    $intake->apply($site, $report, $plan);
    $filed = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source_name', 'questions')->get();
    expect($filed)->toHaveCount(1)
        ->and($filed[0]->title)->toBe('How do water powered sump pumps work?')
        ->and($filed[0]->meta['question']['routed_via'])->toBe('ranking_page');
});

it('merges the marks of one run on one post — the most-asked question leads, the others ride along — and counts posts honestly', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $silo = pumpSilo($site);
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'matched_silo_id' => $silo->id, 'slug' => 'cost-breakdown', 'title' => 'Sump Pump Renovation Cost', 'status' => ContentStatus::Published, 'body' => 'x']);
    $url = 'https://acme.test/cost-breakdown/';
    $report = questionReport($site, [
        questionTopic('sump pump installation cost', 373, $url, 58.6),
        questionTopic('how much does it cost to install a sump pump', 78, $url, 57.9),
        questionTopic('cost to install sump pump', 58, $url, 55.9),
    ]);

    $intake = app(QuestionIntake::class);
    $done = $intake->apply($site, $report, $intake->plan($site, $report, 15));
    $mark = $post->fresh()->meta['question_refresh'];

    expect($done['marked'])->toBe(3)->and($done['posts_marked'])->toBe(1)
        ->and($mark['query'])->toBe('sump pump installation cost')
        ->and($mark['impressions'])->toBe(373)
        ->and(array_column($mark['also'], 'query'))->toBe(['how much does it cost to install a sump pump', 'cost to install sump pump'])
        ->and(app(BlogBoard::class)->questionRefreshMarks($site->id)[0]['also'])->toBe(['how much does it cost to install a sump pump', 'cost to install sump pump']);

    // Order of arrival does not decide the lead: a bigger question marked second takes over.
    $later = questionReport($site, [
        questionTopic('cost to install sump pump', 58, $url, 55.9),
        questionTopic('sump pump installation cost', 373, $url, 58.6),
    ]);
    $intake->apply($site, $later, $intake->plan($site, $later, 15));
    $mark = $post->fresh()->meta['question_refresh'];
    expect($mark['query'])->toBe('sump pump installation cost')->and(array_column($mark['also'], 'query'))->toBe(['cost to install sump pump']);
});

it('carries a refresh done within the cooldown onto next month\'s mark, and drops an old one so the button returns', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $silo = pumpSilo($site);
    $url = 'https://acme.test/cost-breakdown/';
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'matched_silo_id' => $silo->id, 'slug' => 'cost-breakdown', 'title' => 'Sump Pump Installation Cost', 'status' => ContentStatus::Published, 'body' => 'x',
        'meta' => ['question_refresh' => ['query' => 'sump pump installation cost', 'impressions' => 300, 'run' => 'old-run', 'mined_at' => '2026-10-04T04:30:00+00:00',
            'refreshed_at' => now()->subDays(10)->toIso8601String(), 'answer' => 'A few hundred to a few thousand.', 'faq' => [['question' => 'q?', 'answer' => 'a']], 'proposal' => ['before' => [], 'after' => [], 'changed' => []]]]]);
    $report = questionReport($site, [questionTopic('sump pump installation cost', 373, $url, 40.0)]);
    $intake = app(QuestionIntake::class);

    $intake->apply($site, $report, $intake->plan($site, $report, 15));
    $mark = $post->fresh()->meta['question_refresh'];
    expect($mark['impressions'])->toBe(373)                         // the new run's numbers …
        ->and($mark['refreshed_at'])->not->toBeNull()                 // … with this month's refresh still on record
        ->and($mark['answer'])->toBe('A few hundred to a few thousand.')
        ->and(app(BlogBoard::class)->questionRefreshMarks($site->id)[0]['state'])->toBe('refreshed');

    $post->forceFill(['meta' => ['question_refresh' => [...$mark, 'refreshed_at' => now()->subDays(45)->toIso8601String()]]])->save();
    $intake->apply($site, $report, $intake->plan($site, $report, 15));
    expect($post->fresh()->meta['question_refresh'])->not->toHaveKey('refreshed_at')
        ->and(app(BlogBoard::class)->questionRefreshMarks($site->id)[0]['state'])->toBe('idle');
});

it('keeps a post the Refresh moved into the pipeline on the panel as in review', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'r', 'title' => 'In review post', 'status' => ContentStatus::NeedsReview, 'body' => 'x',
        'meta' => ['question_refresh' => ['query' => 'how long do sump pumps last', 'impressions' => 142, 'refreshed_at' => now()->toIso8601String()]]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'slug' => 'c', 'title' => 'Candidate post', 'status' => ContentStatus::Candidate, 'body' => null,
        'meta' => ['question_refresh' => ['query' => 'never shown', 'impressions' => 1]]]);

    $marks = app(BlogBoard::class)->questionRefreshMarks($site->id);

    expect($marks)->toHaveCount(1)
        ->and($marks[0]['title'])->toBe('In review post')
        ->and($marks[0]['state'])->toBe('in_review')
        ->and($marks[0]['status'])->toBe('needs_review');
});
