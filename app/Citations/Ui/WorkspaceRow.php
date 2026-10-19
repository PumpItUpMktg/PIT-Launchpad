<?php

namespace App\Citations\Ui;

use App\Citations\LinkEvidence;
use Illuminate\Support\Carbon;

/**
 * One directory row in the location workspace (§ Citations UI, PR C): the directory, its resolved status chip,
 * whether we can submit it, and the NAP-match summary. `sortRank` encodes the default work order —
 * mismatch → stalled → rejected → missing → submitted → live → not-scanned → not-relevant.
 */
final readonly class WorkspaceRow
{
    /**
     * @param  array{key: string, label: string, color: string}  $chip
     */
    public function __construct(
        public ?string $statusId,
        public string $directoryId,
        public string $directoryName,
        public ?string $homepageUrl,
        public ?string $listingUrl,
        public string $tierLabel,
        public bool $isLocal,
        public bool $submittable,
        public array $chip,
        public string $napMatchSummary,
        public ?Carbon $lastCheckedAt,
        public bool $eligible,
        public int $sortRank,
        /** The sibling location this directory's found page belongs to (the URL is theirs, not this location's). */
        public ?string $listedFor = null,
        /**
         * What the backlink index says: the directory's strongest link to the site and where it lands
         * (see {@see LinkEvidence}); null when the index holds no link from it.
         *
         * @var array{state: string, label: string, listing_url: string, target_path: string, dofollow: bool, rank: int|null, last_seen: string|null, index_broken: bool}|null
         */
        public ?array $link = null,
        /** Where the user creates the listing: the directory's submission URL, else its home page. Set on missing / unscanned rows. */
        public ?string $createUrl = null,
        /** 'Create listing' when the directory has a submission URL, 'Open site' when only its home page is known. */
        public ?string $createLabel = null,
    ) {}
}
