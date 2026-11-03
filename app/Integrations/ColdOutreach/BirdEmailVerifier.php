<?php

namespace App\Integrations\ColdOutreach;

use App\Enums\ConnectionProvider;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;

/**
 * Bird email lookup (`POST /v1/lookup/email/batch`, up to 1,000 per call). Every answered address is
 * billed, so each chunk carries a deterministic Idempotency-Key: a retry inside Bird's replay window
 * returns the stored verdicts instead of buying them twice. The regional host is read from the key
 * prefix (`bk_us1_…` / `bk_eu1_…`) unless BIRD_BASE_URL overrides it.
 */
class BirdEmailVerifier implements EmailVerifier
{
    public const CHUNK = 500;

    public function __construct(
        private readonly Http $http,
        private readonly OutreachCredentials $credentials,
        private readonly ?string $baseUrl = null,
        private readonly int $timeout = 60,
    ) {}

    public function verify(Site $site, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $key = $this->credentials->apiKey($site, ConnectionProvider::Bird);
        $verdicts = [];

        foreach (array_chunk($emails, self::CHUNK) as $chunk) {
            $response = $this->http
                ->withToken($key)
                ->acceptJson()
                ->timeout($this->timeout)
                ->withHeaders(['Idempotency-Key' => 'cold-verify/'.$site->id.'/'.sha1(implode("\n", $chunk))])
                ->retry(3, 500, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 409)), throw: false)
                ->post($this->host($key).'/v1/lookup/email/batch', ['emails' => array_values($chunk)]);

            if (! $response->successful()) {
                throw new ColdOutreachException(
                    'Bird lookup HTTP '.$response->status().': '.$this->errorText($response->json()),
                    $response->status(),
                    fatal: in_array($response->status(), [401, 402, 403], true) || $response->json('code') === 'E22001',
                );
            }

            $rows = (array) ($response->json('data') ?? []);
            foreach (array_values($chunk) as $i => $email) {
                $row = $rows[$i] ?? null;
                $verdicts[] = is_array($row)
                    ? EmailVerdict::fromArray($row, $email)
                    : new EmailVerdict($email, 'unknown', 0);
            }
        }

        return $verdicts;
    }

    private function host(string $key): string
    {
        if ($this->baseUrl !== null && $this->baseUrl !== '') {
            return rtrim($this->baseUrl, '/');
        }
        $region = preg_match('/^b[km]_([a-z]+\d+)_/', $key, $m) === 1 ? $m[1] : 'us1';

        return "https://{$region}.platform.bird.com";
    }

    private function errorText(mixed $body): string
    {
        if (! is_array($body)) {
            return 'no body';
        }

        return (string) ($body['message'] ?? $body['title'] ?? $body['code'] ?? 'error');
    }
}
