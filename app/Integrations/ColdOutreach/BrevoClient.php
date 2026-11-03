<?php

namespace App\Integrations\ColdOutreach;

use App\Enums\ConnectionProvider;
use App\Models\ColdContact;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;

/**
 * Brevo contacts API (`POST /v3/contacts` with `updateEnabled`), so a contact that already exists is
 * updated and added to the list instead of failing. Brevo answers 201 `{id}` on create and 204 (no
 * body) on update. Only Brevo's default FIRSTNAME / LASTNAME attributes are sent — custom attributes
 * must be created in Brevo first or the call is rejected.
 */
class BrevoClient implements MarketingPlatform
{
    public function __construct(
        private readonly Http $http,
        private readonly OutreachCredentials $credentials,
        private readonly string $baseUrl = 'https://api.brevo.com/v3',
        private readonly int $timeout = 30,
    ) {}

    public function upsertContact(Site $site, int $listId, ColdContact $contact): ?string
    {
        $attributes = array_filter([
            'FIRSTNAME' => $contact->first_name,
            'LASTNAME' => $contact->last_name,
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->http
            ->withHeaders(['api-key' => $this->credentials->apiKey($site, ConnectionProvider::Brevo)])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(3, 1000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false)
            ->post($this->baseUrl.'/contacts', array_filter([
                'email' => $contact->email,
                'attributes' => $attributes === [] ? null : $attributes,
                'listIds' => [$listId],
                'updateEnabled' => true,
            ], fn ($v) => $v !== null));

        if (! $response->successful()) {
            throw new ColdOutreachException(
                'Brevo add contact HTTP '.$response->status().': '.(string) ($response->json('message') ?? $response->json('code') ?? 'error'),
                $response->status(),
                fatal: in_array($response->status(), [401, 403], true),
            );
        }

        $id = $response->json('id');

        return $id !== null ? (string) $id : null;
    }
}
