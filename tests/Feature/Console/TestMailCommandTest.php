<?php

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

it('sends one test message through the configured mailer and names the mailer + from address', function () {
    Mail::fake();
    config(['mail.default' => 'postmark', 'mail.from.address' => 'hello@pumpitup.example', 'mail.from.name' => 'Launchpad']);

    $this->artisan('launchpad:test-mail', ['to' => 'eric@example.com'])
        ->expectsOutputToContain('Mailer: postmark · From: Launchpad <hello@pumpitup.example> · To: eric@example.com')
        ->assertSuccessful();

    Mail::assertSent(fn (Mailable $mail): bool => $mail->hasTo('eric@example.com'));
});

it('fails loudly when the mailer is log — nothing left the server', function () {
    config(['mail.default' => 'log']);

    $this->artisan('launchpad:test-mail', ['to' => 'eric@example.com'])
        ->expectsOutputToContain('nothing left the server')
        ->assertFailed();
});

it('refuses a non-address', function () {
    $this->artisan('launchpad:test-mail', ['to' => 'not-an-email'])->assertFailed();
});
