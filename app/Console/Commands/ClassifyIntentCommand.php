<?php

namespace App\Console\Commands;

use App\Enums\KeywordIntent;
use App\Enums\SpokeTag;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Spoke;
use Illuminate\Console\Command;

/**
 * One-shot INTENT backfill (longtail relay §1): classify every spoke/keyword that predates the
 * intent tag. New trees get intent from the expansion call itself; this covers existing records
 * so the routing rule has a complete input. Batched (50 terms per Claude call), strict JSON,
 * idempotent — already-tagged records are skipped, so re-running is safe.
 */
class ClassifyIntentCommand extends Command
{
    protected $signature = 'launchpad:classify-intent {--site= : limit to one site id} {--batch=50 : terms per model call}';

    protected $description = 'Backfill the search-intent tag (transactional/commercial/informational) on untagged spokes and keywords.';

    public function handle(ClaudeClient $claude): int
    {
        $spokes = Spoke::withoutGlobalScope(SiteScope::class)
            ->whereNull('intent')
            ->where('tag', '!=', SpokeTag::Fringe->value)
            ->when($this->option('site'), fn ($q, $site) => $q->where('site_id', $site))
            ->get();

        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->whereNull('intent')
            ->when($this->option('site'), fn ($q, $site) => $q->where('site_id', $site))
            ->get();

        // One classification per unique TERM; both record sets share it.
        $terms = [];
        foreach ($spokes as $spoke) {
            $term = mb_strtolower(trim((string) ($spoke->primary_keyword ?? $spoke->head_keyword ?? $spoke->name)));
            if ($term !== '') {
                $terms[$term] = true;
            }
        }
        foreach ($keywords as $keyword) {
            $term = mb_strtolower(trim((string) $keyword->query));
            if ($term !== '') {
                $terms[$term] = true;
            }
        }
        $terms = array_keys($terms);

        if ($terms === []) {
            $this->info('Nothing to classify — every spoke and keyword already carries an intent.');

            return self::SUCCESS;
        }

        $classified = [];
        foreach (array_chunk($terms, max(1, (int) $this->option('batch'))) as $chunk) {
            foreach ($this->classify($claude, $chunk) as $term => $intent) {
                $classified[$term] = $intent;
            }
        }

        $spokesTagged = 0;
        foreach ($spokes as $spoke) {
            $term = mb_strtolower(trim((string) ($spoke->primary_keyword ?? $spoke->head_keyword ?? $spoke->name)));
            if (isset($classified[$term])) {
                $spoke->forceFill(['intent' => $classified[$term]])->save();
                $spokesTagged++;
            }
        }

        $keywordsTagged = 0;
        foreach ($keywords as $keyword) {
            $term = mb_strtolower(trim((string) $keyword->query));
            if (isset($classified[$term])) {
                $keyword->forceFill(['intent' => $classified[$term]->value])->save();
                $keywordsTagged++;
            }
        }

        $this->info(sprintf('Classified %d terms → tagged %d spokes, %d keywords.', count($classified), $spokesTagged, $keywordsTagged));

        return self::SUCCESS;
    }

    /**
     * One strict-JSON classification call for a batch of terms. Unparseable/unknown entries are
     * simply skipped (a re-run picks them up) — the backfill never guesses.
     *
     * @param  list<string>  $terms
     * @return array<string, KeywordIntent>
     */
    private function classify(ClaudeClient $claude, array $terms): array
    {
        $prompt = 'Classify the SEARCH INTENT of each home-services keyword as exactly one of: '
            .'"transactional" (hire/buy — "sump pump installation near me"), '
            .'"commercial" (evaluating — "best battery backup sump pump", "cost of…"), '
            .'"informational" (learning — "why is my basement wet in spring"). '
            ."Respond with ONLY a JSON object mapping each keyword to its intent, no prose:\n"
            .json_encode($terms, JSON_UNESCAPED_SLASHES);

        $raw = trim($claude->complete($prompt));
        // Tolerate a fenced response.
        $raw = (string) preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded)) {
            $this->warn('Batch response was not valid JSON — skipped '.count($terms).' terms (re-run to retry).');

            return [];
        }

        $out = [];
        foreach ($decoded as $term => $intent) {
            $enum = KeywordIntent::tryFrom(mb_strtolower(trim((string) $intent)));
            if ($enum !== null) {
                $out[mb_strtolower(trim((string) $term))] = $enum;
            }
        }

        return $out;
    }
}
