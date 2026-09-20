<?php

namespace App\Integrations\DataForSeo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What is left in the DataForSEO account, beside the buttons that spend it.
 *
 * Every run button already prices itself — "1,248 requests · ~$2.50" — and none of them knew whether the
 * account could cover it. Running out of credit fails the POST, and because a coverage scan's row is
 * written only AFTER its tasks post, the failure leaves nothing behind: the card goes on saying "no scan
 * for this keyword yet", which reads as "you have not run one" rather than "your run could not pay".
 *
 * Cached, because this is read on render: a balance minutes old is fine for deciding whether $2.50 will
 * clear, and the cache is dropped after a run so the next read reflects the spend.
 *
 * NEVER a blocker. A balance we cannot read is null, and null means proceed exactly as before — an
 * outage at the vendor's account endpoint must not stop work the account can actually afford.
 */
final class AccountBalance
{
    private const KEY = 'dataforseo.balance';

    private const TTL_SECONDS = 300;

    public function __construct(private readonly DataForSeoClient $client) {}

    /** Dollars remaining, or null when the account endpoint could not be read. */
    public function current(): ?float
    {
        /** @var float|null $balance */
        $balance = Cache::remember(self::KEY, self::TTL_SECONDS, function (): ?float {
            try {
                return $this->client->userData()['balance'];
            } catch (Throwable $e) {
                Log::warning('DataForSEO balance unreadable — treating as unknown.', ['error' => $e->getMessage()]);

                return null;
            }
        });

        return $balance;
    }

    /**
     * Whether a run costing $estimate can be paid for. Unknown balance → true: we do not block work on a
     * number we could not read.
     */
    public function covers(float $estimate): bool
    {
        $balance = $this->current();

        return $balance === null || $balance >= $estimate;
    }

    /** Drop the cached figure — called after a run so the next read reflects what it spent. */
    public function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
