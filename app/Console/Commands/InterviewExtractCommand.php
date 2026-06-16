<?php

namespace App\Console\Commands;

use App\Interview\InterviewExtractor;
use App\Interview\SeedExtractionException;
use Illuminate\Console\Command;

/**
 * Read-only verification surface for the owner-interview extractor (PR #1 of the
 * silo-generator arc): run the extractor on a plain business description and
 * pretty-print the structured SiloSeed + VoiceProfile. No persistence — the
 * conversational UI + storage land in a later PR.
 *
 *   launchpad:interview-extract "We're a family plumbing shop in Tucson…" --gbp="Plumber,Water Heater Repair"
 */
class InterviewExtractCommand extends Command
{
    protected $signature = 'launchpad:interview-extract
        {description : the owner business description (plain text)}
        {--gbp= : comma-separated connected-GBP categories/services to ground the seed}';

    protected $description = 'Extract a SiloSeed + VoiceProfile from a business description (read-only preview).';

    public function handle(InterviewExtractor $extractor): int
    {
        $gbpOption = (string) ($this->option('gbp') ?? '');
        $gbp = $gbpOption === ''
            ? null
            : array_values(array_filter(array_map('trim', explode(',', $gbpOption)), fn ($v) => $v !== ''));

        try {
            $result = $extractor->extract((string) $this->argument('description'), $gbp);
        } catch (SeedExtractionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $seed = $result->seed;

        $this->newLine();
        $this->info('Silo Seed');
        $this->line('  Trade:      '.$seed->trade);
        $this->line('  Anchors:    '.$this->fmt($seed->anchorServices));
        $this->line('  Markets:    '.$this->fmt($seed->markets));
        $this->line('  Exclusions: '.$this->fmt($seed->exclusions));
        $this->line('  GBP:        '.($seed->gbpSignals === null ? '(none connected)' : $this->fmt($seed->gbpSignals)));

        $this->newLine();
        $this->info('Voice Profile');
        $this->line((string) json_encode($result->voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $items
     */
    private function fmt(array $items): string
    {
        return $items === [] ? '(none)' : implode(', ', $items);
    }
}
