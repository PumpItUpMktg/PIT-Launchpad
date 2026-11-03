<?php

use App\ColdOutreach\DeliverabilityGate;
use App\ColdOutreach\WarmthRule;
use App\Enums\ColdContactStage;
use App\Integrations\ColdOutreach\EmailVerdict;
use App\Models\ColdContact;
use App\Models\ColdOutreachConfig;

function coldConfig(array $overrides = []): ColdOutreachConfig
{
    return new ColdOutreachConfig(array_merge(['site_id' => 'x'], $overrides));
}

function verdict(string $result, int $confidence, array $flags = []): EmailVerdict
{
    return new EmailVerdict('a@b.test', $result, $confidence, $flags);
}

it('maps Bird verdicts to pipeline stages', function (string $result, int $conf, array $flags, array $config, ColdContactStage $expected) {
    expect(app(DeliverabilityGate::class)->stageFor(verdict($result, $conf, $flags), coldConfig($config)))->toBe($expected);
})->with([
    'valid above floor' => ['valid', 95, [], [], ColdContactStage::Verified],
    'valid below floor' => ['valid', 50, [], [], ColdContactStage::Risky],
    'neutral accepted' => ['neutral', 80, [], [], ColdContactStage::Verified],
    'neutral not accepted' => ['neutral', 80, [], ['accept_neutral' => false], ColdContactStage::Risky],
    'risky role address' => ['risky', 90, ['role'], [], ColdContactStage::Risky],
    'undeliverable' => ['undeliverable', 0, [], [], ColdContactStage::Invalid],
    'typo' => ['typo', 10, [], [], ColdContactStage::Invalid],
    'disposable even if valid' => ['valid', 99, ['disposable'], [], ColdContactStage::Invalid],
    'future verdict, high confidence' => ['deliverable_plus', 90, [], [], ColdContactStage::Verified],
    'future verdict, low confidence' => ['deliverable_plus', 20, [], [], ColdContactStage::Risky],
]);

function warming(array $attrs = []): ColdContact
{
    return new ColdContact(array_merge(['stage' => ColdContactStage::Warming, 'opens' => 0, 'replies' => 0, 'clicks' => 0], $attrs));
}

it('warms a contact on a reply or an interested tag', function () {
    $rule = app(WarmthRule::class);

    expect($rule->evaluate(warming(['replies' => 1]), coldConfig()))->toBe(['stage' => ColdContactStage::Warm, 'reason' => 'replied'])
        ->and($rule->evaluate(warming(['interest_status' => 2]), coldConfig()))->toBe(['stage' => ColdContactStage::Warm, 'reason' => 'marked interested'])
        ->and($rule->evaluate(warming(['opens' => 9]), coldConfig()))->toBeNull()
        ->and($rule->evaluate(warming(['replies' => 1]), coldConfig(['warm_on_reply' => false])))->toBeNull();
});

it('only warms on opens when the site opts in', function () {
    $rule = app(WarmthRule::class);

    expect($rule->evaluate(warming(['opens' => 3]), coldConfig(['warm_min_opens' => 3]))['stage'])->toBe(ColdContactStage::Warm)
        ->and($rule->evaluate(warming(['opens' => 2]), coldConfig(['warm_min_opens' => 3])))->toBeNull();
});

it('disqualifies on bounce, unsubscribe or a negative tag — even after a reply', function () {
    $rule = app(WarmthRule::class);

    expect($rule->evaluate(warming(['replies' => 2, 'instantly_status' => -2]), coldConfig()))->toBe(['stage' => ColdContactStage::Disqualified, 'reason' => 'unsubscribed'])
        ->and($rule->evaluate(warming(['instantly_status' => -1]), coldConfig())['reason'])->toBe('bounced')
        ->and($rule->evaluate(warming(['replies' => 1, 'interest_status' => -1]), coldConfig())['reason'])->toBe('not interested')
        ->and($rule->evaluate(warming(['stage' => ColdContactStage::Warm, 'interest_status' => -2]), coldConfig())['reason'])->toBe('wrong person');
});
