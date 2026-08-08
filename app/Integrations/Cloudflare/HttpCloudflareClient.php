<?php

namespace App\Integrations\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The real Cloudflare API v4 adapter (Bearer token). It does exactly three things: verify the token,
 * resolve a zone by domain, and upsert the one Launchpad WAF skip rule.
 *
 * The rule is written into the zone's `http_request_firewall_custom` phase entrypoint via the Rulesets
 * API, prepended so it evaluates FIRST, with a `skip` action that bypasses remaining custom rules, the
 * managed WAF, and the security products — scoped to `/wp-json/launchpad/*` only. Idempotent: an
 * existing rule carrying our description marker is replaced, never duplicated.
 */
final class HttpCloudflareClient implements CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    private const PHASE = 'http_request_firewall_custom';

    /** The marker that makes the rule idempotent — we find/replace by this exact description. */
    private const RULE_DESCRIPTION = 'Launchpad control-plane sync (auto-managed) — allow /wp-json/launchpad/*';

    private const EXPRESSION = '(http.request.uri.path contains "/wp-json/launchpad/")';

    public function __construct(
        private readonly string $token,
        private readonly int $timeout = 20,
    ) {}

    public function verifyToken(): bool
    {
        if ($this->token === '') {
            return false;
        }

        try {
            $response = $this->http()->get(self::BASE.'/user/tokens/verify');
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('result.status') === 'active';
    }

    public function zoneIdForDomain(string $domain): ?string
    {
        foreach ($this->candidateZones($domain) as $name) {
            try {
                $response = $this->http()->get(self::BASE.'/zones', ['name' => $name, 'status' => 'active']);
            } catch (Throwable) {
                return null;
            }

            $id = $response->successful() ? $response->json('result.0.id') : null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    public function ensureLaunchpadSkipRule(string $zoneId): CloudflareRuleResult
    {
        $entrypoint = self::BASE."/zones/{$zoneId}/rulesets/phases/".self::PHASE.'/entrypoint';

        try {
            $current = $this->http()->get($entrypoint);
        } catch (Throwable $e) {
            return CloudflareRuleResult::failed($e->getMessage());
        }

        // No entrypoint yet (404) is fine — we PUT one into existence. Any other read error aborts.
        if (! $current->successful() && $current->status() !== 404) {
            return CloudflareRuleResult::failed($this->apiError($current));
        }

        $rules = $current->successful() ? (array) ($current->json('result.rules') ?? []) : [];

        // Drop any prior Launchpad-managed rule so we replace rather than stack (idempotent).
        $existed = false;
        $rules = array_values(array_filter($rules, function ($rule) use (&$existed): bool {
            if (is_array($rule) && ($rule['description'] ?? null) === self::RULE_DESCRIPTION) {
                $existed = true;

                return false;
            }

            return true;
        }));

        // Prepend ours so the skip takes effect before any blocking rule.
        array_unshift($rules, [
            'action' => 'skip',
            'action_parameters' => [
                'ruleset' => 'current',                        // skip remaining custom rules in this phase
                'phases' => ['http_request_firewall_managed'], // skip the managed WAF
                'products' => ['waf', 'uaBlock', 'bic', 'hot', 'securityLevel', 'zoneLockdown', 'rateLimit'],
            ],
            'expression' => self::EXPRESSION,
            'description' => self::RULE_DESCRIPTION,
            'enabled' => true,
        ]);

        try {
            $put = $this->http()->put($entrypoint, ['rules' => $rules]);
        } catch (Throwable $e) {
            return CloudflareRuleResult::failed($e->getMessage());
        }

        if (! $put->successful()) {
            return CloudflareRuleResult::failed($this->apiError($put));
        }

        $ruleId = $this->findRuleId((array) ($put->json('result.rules') ?? []));

        return $existed ? CloudflareRuleResult::updated($ruleId) : CloudflareRuleResult::created($ruleId);
    }

    /**
     * Candidate zone names for a host, apex-first-fallback: `www.acme.com` → try `www.acme.com`, then
     * `acme.com`. Cloudflare zones are registrable domains, so we walk labels off the front down to two.
     *
     * @return list<string>
     */
    private function candidateZones(string $domain): array
    {
        $host = strtolower(trim($domain));
        // Tolerate a full URL being passed in.
        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }
        $host = trim($host, '/');
        if ($host === '') {
            return [];
        }

        $labels = explode('.', $host);
        $candidates = [];
        while (count($labels) >= 2) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }

        return $candidates;
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function findRuleId(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (is_array($rule) && ($rule['description'] ?? null) === self::RULE_DESCRIPTION) {
                $id = $rule['id'] ?? null;

                return is_string($id) ? $id : null;
            }
        }

        return null;
    }

    /** A readable Cloudflare error (its `errors[].message`) or the raw status. */
    private function apiError(Response $response): string
    {
        $messages = collect((array) ($response->json('errors') ?? []))
            ->map(fn ($e): string => is_array($e) ? (string) ($e['message'] ?? '') : '')
            ->filter()
            ->implode('; ');

        return $messages !== '' ? $messages : 'Cloudflare API returned HTTP '.$response->status();
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->timeout($this->timeout);
    }
}
