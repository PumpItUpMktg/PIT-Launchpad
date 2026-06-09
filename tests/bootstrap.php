<?php

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| No-live-calls floor for the test suite
|--------------------------------------------------------------------------
|
| Scrub every vendor credential from the environment before the framework
| boots, so no test — current or future — can bill a real API or trip a rate
| limit, even when the host shell exports real secrets (CI runners, dev boxes).
|
| This must happen here rather than via phpunit.xml <env> entries: Laravel's
| Env repository reads $_SERVER / $_ENV ahead of putenv, and a host-exported
| variable lands in $_SERVER where a phpunit <env force="true"> does not reach
| it. Clearing all three is the only reliable scrub. Adapter tests still inject
| their own fakes / Http::fake; this is the floor, not the mechanism. (It is a
| §9 nicety too: secrets stop leaking into test runs.)
|
*/
$secrets = [
    'ANTHROPIC_API_KEY',
    'FAL_KEY',
    'DATAFORSEO_LOGIN',
    'DATAFORSEO_PASSWORD',
    'NEWSAPI_KEY',
    'NEWS_API_KEY',
    'OPENAI_API_KEY',
    'GOOGLE_PROJECT_ID',
    'GOOGLE_MAPS_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'CENSUS_API_KEY',
    'KRAYIN_TOKEN',
    'MAUTIC_CLIENT_ID',
    'MAUTIC_CLIENT_SECRET',
    'CALCOM_API_KEY',
    'FLOWROUTE_ACCESS_KEY',
    'FLOWROUTE_SECRET_KEY',
    'POSTMARK_TOKEN',
    'RESEND_API_KEY',
    'SLACK_BOT_USER_OAUTH_TOKEN',
    'SENTRY_LARAVEL_DSN',
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AWS_BUCKET',
    'R2_ACCESS_KEY_ID',
    'R2_SECRET_ACCESS_KEY',
    'R2_BUCKET',
    'R2_ENDPOINT',
];

foreach ($secrets as $secret) {
    unset($_ENV[$secret], $_SERVER[$secret]);
    putenv($secret);
}
