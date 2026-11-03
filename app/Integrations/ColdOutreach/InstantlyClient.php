<?php

namespace App\Integrations\ColdOutreach;

use App\Enums\ConnectionProvider;
use App\Models\ColdContact;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Instantly API v2 (`https://api.instantly.ai/api/v2`, bearer key with leads scope). Leads are added
 * with `skip_if_in_workspace` so a contact already being mailed from another campaign is never
 * double-sequenced; engagement is read back with `POST /leads/list` (cursor-paged, 100 per page).
 */
class InstantlyClient implements WarmupPlatform
{
    public function __construct(
        private readonly Http $http,
        private readonly OutreachCredentials $credentials,
        private readonly string $baseUrl = 'https://api.instantly.ai/api/v2',
        private readonly int $timeout = 30,
    ) {}

    public function addLead(Site $site, string $campaignId, ColdContact $contact): WarmupLead
    {
        $body = array_filter([
            'campaign' => $campaignId,
            'email' => $contact->email,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'company_name' => $contact->company,
            'job_title' => $contact->job_title,
            'phone' => $contact->phone,
            'website' => $contact->website,
            'custom_variables' => $this->customVariables($contact->custom ?? []),
            'skip_if_in_workspace' => true,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $response = $this->request($site)->post($this->baseUrl.'/leads', $body);
        $this->guard($response, 'add lead');

        $json = $response->json();

        return WarmupLead::fromArray(is_array($json) ? $json + ['email' => $contact->email] : ['email' => $contact->email]);
    }

    public function listLeads(Site $site, string $campaignId, ?string $cursor = null): array
    {
        $response = $this->request($site)->post($this->baseUrl.'/leads/list', array_filter([
            'campaign' => $campaignId,
            'limit' => 100,
            'starting_after' => $cursor,
        ], fn ($v) => $v !== null));
        $this->guard($response, 'list leads');

        $leads = [];
        foreach ((array) ($response->json('items') ?? []) as $row) {
            if (is_array($row)) {
                $leads[] = WarmupLead::fromArray($row);
            }
        }
        $next = $response->json('next_starting_after');

        return ['leads' => $leads, 'next' => is_string($next) && $next !== '' ? $next : null];
    }

    private function request(Site $site): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->withToken($this->credentials->apiKey($site, ConnectionProvider::Instantly))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(3, 1000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }

    private function guard(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ColdOutreachException(
            "Instantly {$what} HTTP ".$response->status().': '.(string) ($response->json('message') ?? $response->json('error') ?? 'error'),
            $response->status(),
            fatal: in_array($response->status(), [401, 402, 403], true),
        );
    }

    /**
     * Instantly custom variables must be scalar.
     *
     * @param  array<string, mixed>  $custom
     * @return array<string, string|int|float|bool>
     */
    private function customVariables(array $custom): array
    {
        return array_filter($custom, fn ($v) => is_scalar($v));
    }
}
