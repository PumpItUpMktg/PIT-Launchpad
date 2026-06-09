<?php

namespace App\Integrations\Conversions;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Models\Site;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;

/**
 * Mautic conversion source: form submissions on the configured lead-gen form,
 * pulled since the cursor and collapsed to dated counts. OAuth2 client-credentials
 * (a shared platform instance). Dormant — returns no records — until the instance
 * is stood up and creds + form id are configured.
 *
 * UNRESOLVED (see report): the exact "conversion" definition (which form(s) /
 * campaign goals) and how a Mautic submission maps to a site_id (one form per
 * client vs a shared form with a site field) need Eric's confirmation.
 */
class MauticConversionProvider implements ConversionProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $formId,
        private readonly int $timeout = 30,
    ) {}

    public function source(): ConversionSource
    {
        return ConversionSource::Mautic;
    }

    /**
     * @return list<ConversionRecord>
     */
    public function pull(Site $site, DateTimeInterface $since): array
    {
        if ($this->baseUrl === '' || $this->clientId === '' || $this->clientSecret === '' || $this->formId === null) {
            return []; // dormant until deployed/configured
        }

        $response = $this->http
            ->withToken($this->accessToken())
            ->timeout($this->timeout)
            ->retry(3, 400, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false)
            ->get(rtrim($this->baseUrl, '/')."/api/forms/{$this->formId}/submissions", [
                'where[0][col]' => 'dateSubmitted',
                'where[0][expr]' => 'gte',
                'where[0][val]' => DateTimeImmutable::createFromInterface($since)->format('Y-m-d H:i:s'),
            ]);

        if (! $response->successful()) {
            throw new ConversionSourceException(
                'Mautic submissions HTTP '.$response->status(),
                $response->status(),
                fatal: in_array($response->status(), [401, 403], true),
            );
        }

        $dates = [];
        foreach ((array) ($response->json('submissions') ?? []) as $submission) {
            $submitted = is_array($submission) ? ($submission['dateSubmitted'] ?? null) : null;
            if (is_string($submitted) && $submitted !== '') {
                $dates[] = new DateTimeImmutable($submitted);
            }
        }

        return ConversionRecord::dailyCounts(ConversionType::Form, ConversionSource::Mautic, $dates);
    }

    private function accessToken(): string
    {
        return (string) $this->cache->remember(
            'mautic:token:'.sha1($this->baseUrl.$this->clientId),
            3300, // < Mautic's 1h access-token lifetime
            function (): string {
                $response = $this->http
                    ->asForm()
                    ->timeout($this->timeout)
                    ->post(rtrim($this->baseUrl, '/').'/oauth/v2/token', [
                        'grant_type' => 'client_credentials',
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                    ]);

                if (! $response->successful()) {
                    throw new ConversionSourceException(
                        'Mautic OAuth HTTP '.$response->status(),
                        $response->status(),
                        fatal: in_array($response->status(), [400, 401], true),
                    );
                }

                return (string) $response->json('access_token');
            },
        );
    }
}
