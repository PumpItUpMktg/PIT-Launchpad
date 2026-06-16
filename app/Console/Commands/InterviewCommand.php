<?php

namespace App\Console\Commands;

use App\Interview\Conversation\Interviewer;
use App\Interview\Conversation\InterviewSession;
use App\Interview\InterviewExtractor;
use App\Interview\InterviewPersister;
use App\Interview\SeedExtractionException;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Run the conversational owner interview end to end against a Site (PR #2 of the
 * silo-generator arc): the interviewer asks one question at a time until it has
 * enough, then the proven extractor turns the transcript into a SiloSeed + voice
 * payload, and — on confirm — the persister writes a SiloBlueprint + an activated
 * VoiceProfile. Type "done" to wrap up early.
 *
 *   launchpad:interview {site} [--gbp="Plumber,Water Heater Repair"]
 */
class InterviewCommand extends Command
{
    protected $signature = 'launchpad:interview
        {site : the Site id to onboard}
        {--gbp= : comma-separated connected-GBP categories/services to ground the seed}';

    protected $description = 'Run the conversational owner interview and persist the seed + voice profile.';

    public function handle(Interviewer $interviewer, InterviewExtractor $extractor, InterviewPersister $persister): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $gbpOption = (string) ($this->option('gbp') ?? '');
        $gbp = $gbpOption === ''
            ? null
            : array_values(array_filter(array_map('trim', explode(',', $gbpOption)), fn ($v) => $v !== ''));

        $session = InterviewSession::start($interviewer, $gbp);
        $this->newLine();
        $this->line('<info>Interviewer:</info> '.$this->lastMessage($session));

        while (! $session->isReady()) {
            $answer = (string) $this->ask('You');
            $reply = $session->submit($interviewer, $answer);
            $this->line('<info>Interviewer:</info> '.$reply->message);

            if (strcasecmp(trim($answer), 'done') === 0) {
                break;
            }
        }

        try {
            $result = $session->extract($extractor);
        } catch (SeedExtractionException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $seed = $result->seed;
        $this->newLine();
        $this->info('Extracted seed');
        $this->line('  Trade:      '.$seed->trade);
        $this->line('  Anchors:    '.$this->fmt($seed->anchorServices));
        $this->line('  Markets:    '.$this->fmt($seed->markets));
        $this->line('  Exclusions: '.$this->fmt($seed->exclusions));
        $this->newLine();
        $this->info('Voice profile');
        $this->line((string) json_encode($result->voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        if (! $this->confirm('Persist this seed + voice profile to '.($site->name ?? $site->id).'?', true)) {
            $this->warn('Discarded — nothing was saved.');

            return self::SUCCESS;
        }

        $persisted = $persister->persist($site, $result);

        $this->info("Saved blueprint {$persisted->blueprint->id}; voice v{$persisted->voice->version} is now active.");

        return self::SUCCESS;
    }

    private function lastMessage(InterviewSession $session): string
    {
        $turns = $session->turns();

        return $turns === [] ? '' : $turns[array_key_last($turns)]->text;
    }

    /**
     * @param  list<string>  $items
     */
    private function fmt(array $items): string
    {
        return $items === [] ? '(none)' : implode(', ', $items);
    }
}
