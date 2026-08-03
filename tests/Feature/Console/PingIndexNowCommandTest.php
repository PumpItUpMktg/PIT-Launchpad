<?php

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Models\Site;

it('submits a named site to IndexNow and reports the count', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);

    $stub = Mockery::mock(IndexNowSubmitter::class);
    $stub->shouldReceive('submitSite')->once()->with(Mockery::on(fn (Site $s) => $s->is($site)))
        ->andReturn(['ok' => true, 'submitted' => 7, 'status' => 200, 'reason' => null]);
    app()->instance(IndexNowSubmitter::class, $stub);

    $this->artisan('launchpad:indexnow', ['--site' => 'SPG'])
        ->expectsOutputToContain('submitted 7 URL(s)')
        ->assertSuccessful();
});

it('fails when IndexNow rejects', function () {
    Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);

    $stub = Mockery::mock(IndexNowSubmitter::class);
    $stub->shouldReceive('submitSite')->andReturn(['ok' => false, 'submitted' => 0, 'status' => 403, 'reason' => 'key not served']);
    app()->instance(IndexNowSubmitter::class, $stub);

    $this->artisan('launchpad:indexnow', ['--site' => 'SPG'])->assertFailed();
});
