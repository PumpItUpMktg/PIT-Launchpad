<?php

namespace App\Console\Commands;

use App\ContentEngine\Drafting\DraftAttempt;
use App\ContentEngine\Drafting\Drafter;
use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Drafting\DraftPayload;
use App\ContentEngine\Drafting\DraftRequest;
use App\ContentEngine\Drafting\GroundingAssembler;
use App\ContentEngine\Drafting\PageDrafter;
use App\ContentEngine\Drafting\PageGroundingAssembler;
use App\Enums\ContentKind;
use App\Integrations\Claude\ClaudeClientFactory;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-shot diagnostic that runs the EXACT live Drafter call path against a real
 * candidate and reports it verbosely — the drafting analog of verify-vendors.
 *
 * It exists because the Claude vendor probe (a Haiku/no-thinking completion) can
 * pass while the Drafter fails: the Drafter runs a different client (the drafting
 * model, adaptive thinking) over a large grounded prompt. This probe resolves the
 * real Drafter from the container — the same binding the live pipeline uses — so
 * it reproduces the actual failure (wrong drafting model, unsupported thinking,
 * token limits, malformed/empty output) with full detail.
 *
 * Makes a real Claude call. Read-only: it NEVER writes to the candidate (no
 * status transition, no marker) — it only reports. Console-only; never in CI.
 */
class ProbeDrafterCommand extends Command
{
    protected $signature = 'launchpad:probe-drafter {content : A real Content id (post candidate or page)} {--market= : Market id for local injection} {--show-prompt : Print the full system + user prompt}';

    protected $description = 'Run the live drafter path against a post candidate OR a page and report it verbosely (model, grounding, prompt, raw response, parse). Makes a real Claude call — console-only, never in CI.';

    public function handle(
        ClaudeClientFactory $factory,
        GroundingAssembler $assembler,
        Drafter $drafter,
        PageGroundingAssembler $pageAssembler,
        PageDrafter $pageDrafter,
    ): int {
        $candidate = Content::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('content'));

        if ($candidate === null) {
            $this->error('Content not found.');

            return self::FAILURE;
        }

        $this->warn('Live drafter probe — this makes a real Claude call on the drafting model. Read-only: the content is not modified.');
        $this->line('');

        // The divergence the probe exists to expose: drafting client vs the
        // scoring client the Claude vendor probe exercises.
        $drafting = $factory->drafting()->describe();
        $scoring = $factory->scoring()->describe();
        $this->line('Candidate : '.$candidate->id.'  (site '.$candidate->site_id.', kind '.$candidate->kind->value.', status '.$candidate->status->value.')');
        $this->line('Drafting  : model='.$drafting['model'].'  thinking='.($drafting['thinking'] ?? 'none').'  max_tokens='.$drafting['max_tokens']);
        $this->line('Scoring   : model='.$scoring['model'].'  thinking='.($scoring['thinking'] ?? 'none').'  (what the Claude vendor probe tests)');

        if ((string) config('services.anthropic.key') === '') {
            $this->error('ANTHROPIC_API_KEY not set — cannot run the live drafter path.');

            return self::FAILURE;
        }

        // Both kinds report identically below; only the grounding + prompt + the
        // attempt closure differ (post Drafter vs PageDrafter, via the common
        // attempt(): DraftAttempt contract). Grounding assembly can throw (e.g. a
        // page with no kit) — that's a failure too.
        try {
            if ($candidate->kind === ContentKind::Page) {
                $grounding = $pageAssembler->assemble($candidate);
                $this->line('Grounding : voice v'.$grounding->voiceProfileVersion
                    .', services='.count($grounding->services)
                    .', problems='.count($grounding->problems)
                    .', proof='.count($grounding->proof)
                    .', markets='.count($grounding->markets)
                    .', kit_slots='.count($grounding->kit->slots));
                $preview = $pageDrafter->preview($grounding);
                $attemptFn = fn (): DraftAttempt => $pageDrafter->attempt($grounding);
            } else {
                $request = DraftRequest::forCandidate($candidate, $this->option('market') !== null ? (string) $this->option('market') : null);
                $newsGrounding = $assembler->assemble($request);
                $this->line('Grounding : voice v'.$newsGrounding->voiceProfileVersion
                    .', claims='.count($newsGrounding->claims)
                    .', sources='.count($newsGrounding->sources)
                    .', towns='.count($newsGrounding->towns)
                    .', kit='.($newsGrounding->kit !== null ? 'yes' : 'none'));
                $preview = $drafter->preview($request, $newsGrounding);
                $attemptFn = fn (): DraftAttempt => $drafter->attempt($request, $newsGrounding);
            }
        } catch (Throwable $e) {
            return $this->reportFailure(DraftFailure::fromException($e));
        }

        $this->line('Prompt    : system '.strlen($preview['system']).' chars, user '.strlen($preview['prompt']).' chars');
        if ($this->option('show-prompt')) {
            $this->line('');
            $this->line('--- SYSTEM ---');
            $this->line($preview['system']);
            $this->line('--- USER ---');
            $this->line($preview['prompt']);
        }
        $this->line('');

        try {
            $attempt = $attemptFn();
        } catch (Throwable $e) {
            return $this->reportFailure(DraftFailure::fromException($e));
        }

        $c = $attempt->completion;
        $this->line('Stop/usage: stop_reason='.($c->stopReason ?? '—')
            .'  in='.($c->inputTokens ?? '—')
            .'  out='.($c->outputTokens ?? '—')
            .'  thinking='.($c->thinkingTokens ?? '—'));
        $this->line('Raw resp  : '.strlen($attempt->rawResponse).' chars');
        $this->line(rtrim($this->indent($this->truncate($attempt->rawResponse, 800))));
        $this->line('');

        if (! $this->producedDraft($candidate->kind, $attempt->payload)) {
            return $this->reportFailure(DraftFailure::emptyResponse($attempt->rawResponse, $attempt->completion));
        }

        $payload = $attempt->payload;
        $this->info('DRAFTED — the live drafter path produced content.');
        $this->line('  seo.title : '.($payload->seo->title !== '' ? $payload->seo->title : '(empty)'));
        $this->line('  body      : '.($payload->body !== null ? strlen($payload->body).' chars' : '—'));
        $this->line('  slots     : '.($payload->slots !== null ? implode(', ', array_keys($payload->slots)) : '—'));
        $this->line('  images    : '.count($payload->images).' spec(s)');

        return self::SUCCESS;
    }

    private function producedDraft(ContentKind $kind, DraftPayload $payload): bool
    {
        if ($kind === ContentKind::Page) {
            return is_array($payload->slots) && $payload->slots !== [];
        }

        return is_string($payload->body) && trim($payload->body) !== '';
    }

    private function reportFailure(DraftFailure $failure): int
    {
        $this->error('DRAFT FAILED — '.$failure->reason);
        if ($failure->stopReason !== null) {
            $this->line('  stop_reason : '.$failure->stopReason
                .'  (in '.($failure->inputTokens ?? '—')
                .', out '.($failure->outputTokens ?? '—')
                .', thinking '.($failure->thinkingTokens ?? '—').')');
        }
        if ($failure->httpStatus !== null) {
            $this->line('  Anthropic HTTP status : '.$failure->httpStatus);
        }
        if ($failure->exceptionClass !== null) {
            $this->line('  Exception : '.$failure->exceptionClass);
            $this->line('  Message   : '.($failure->exceptionMessage ?? ''));
        }
        if ($failure->rawResponseExcerpt !== null) {
            $this->line('  Raw excerpt :');
            $this->line($this->indent($failure->rawResponseExcerpt));
        }

        return self::FAILURE;
    }

    private function truncate(string $value, int $limit): string
    {
        return strlen($value) > $limit ? substr($value, 0, $limit).'…' : $value;
    }

    private function indent(string $value): string
    {
        return '    '.str_replace("\n", "\n    ", $value);
    }
}
