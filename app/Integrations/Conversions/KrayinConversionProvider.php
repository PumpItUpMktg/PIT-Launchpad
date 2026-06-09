<?php

namespace App\Integrations\Conversions;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Models\Site;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;

/**
 * Krayin conversion source: leads that reached a won pipeline stage (a booked /
 * closed job), pulled since the cursor and collapsed to dated counts. Token auth.
 * Dormant — returns no records — until the instance is stood up and configured.
 *
 * UNRESOLVED (see report): Krayin's community REST surface may not expose
 * pipeline/won-stage leads directly — if `GET /api/v1/leads` can't filter by stage
 * + closed date, this needs an alternative integration path (custom endpoint / DB
 * view). The won-stage match is config-driven. Per-lead deal value has no column
 * on the Conversion model today (flagged). Tenant→lead mapping needs Eric.
 */
class KrayinConversionProvider implements ConversionProvider
{
    /**
     * @param  list<string>  $wonStages  pipeline stage codes/names counted as a conversion
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly array $wonStages = ['won'],
        private readonly int $timeout = 30,
    ) {}

    public function source(): ConversionSource
    {
        return ConversionSource::Krayin;
    }

    /**
     * @return list<ConversionRecord>
     */
    public function pull(Site $site, DateTimeInterface $since): array
    {
        if ($this->baseUrl === '' || $this->token === '') {
            return []; // dormant until deployed/configured
        }

        $response = $this->http
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(3, 400, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false)
            ->get(rtrim($this->baseUrl, '/').'/api/v1/leads', [
                'updated_at_gte' => DateTimeImmutable::createFromInterface($since)->format('Y-m-d H:i:s'),
            ]);

        if (! $response->successful()) {
            throw new ConversionSourceException(
                'Krayin leads HTTP '.$response->status(),
                $response->status(),
                fatal: in_array($response->status(), [401, 403], true),
            );
        }

        $won = array_map('strtolower', $this->wonStages);
        $dates = [];

        foreach ((array) ($response->json('data') ?? []) as $lead) {
            if (! is_array($lead) || ! $this->isWon($lead, $won)) {
                continue;
            }
            $closed = (string) ($lead['closed_at'] ?? $lead['updated_at'] ?? '');
            if ($closed !== '') {
                $dates[] = new DateTimeImmutable($closed);
            }
        }

        return ConversionRecord::dailyCounts(ConversionType::Lead, ConversionSource::Krayin, $dates);
    }

    /**
     * @param  array<string, mixed>  $lead
     * @param  list<string>  $won
     */
    private function isWon(array $lead, array $won): bool
    {
        $stage = $lead['lead_pipeline_stage']['code']
            ?? $lead['lead_pipeline_stage']['name']
            ?? $lead['stage']
            ?? $lead['status']
            ?? null;

        return is_scalar($stage) && in_array(strtolower((string) $stage), $won, true);
    }
}
