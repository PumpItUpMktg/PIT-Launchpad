<?php

namespace App\ContentEngine\Drafting;

use App\Enums\ContentKind;
use App\Models\Content;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The shared draft FAILURE machinery — single-sourced so every kind fails
 * identically: a thrown model call OR an empty/unparseable payload becomes a
 * DraftFailure that is stamped on the row (without transitioning its status),
 * logged with the full cause (exception class/message, HTTP status, stop_reason +
 * token split, raw excerpt), and thrown as DraftFailedException. `fail()` is
 * public so a sibling can route a kind-specific rejection (e.g. a page that
 * doesn't satisfy its kit schema) through the same marker + log + throw.
 */
final class DraftGuard
{
    /**
     * Run a draft attempt under the failure machinery, returning the attempt only
     * when a real payload was produced.
     *
     * @param  callable(): DraftAttempt  $attempt
     */
    public function run(ContentKind $kind, ?Content $markOn, ?string $contentId, string $siteId, callable $attempt): DraftAttempt
    {
        try {
            $result = $attempt();
        } catch (Throwable $e) {
            $this->fail($markOn, $contentId, $siteId, $kind, DraftFailure::fromException($e), $e);
        }

        if (! $this->payloadHasDraft($kind, $result->payload)) {
            $this->fail($markOn, $contentId, $siteId, $kind, DraftFailure::emptyResponse($result->rawResponse, $result->completion), null);
        }

        return $result;
    }

    /**
     * @return never
     */
    public function fail(?Content $markOn, ?string $contentId, string $siteId, ContentKind $kind, DraftFailure $failure, ?Throwable $previous): void
    {
        if ($markOn !== null) {
            $this->mark($markOn, $failure);
        }

        Log::error('Draft generation failed — left undrafted.', [
            'content_id' => $contentId,
            'site_id' => $siteId,
            'kind' => $kind->value,
            ...$failure->logContext(),
        ]);

        throw DraftFailedException::fromFailure($contentId, $failure, $previous);
    }

    /**
     * A draft is "produced" only with real output: a post needs body HTML, a page
     * needs at least one filled slot. An empty payload is a failed model call.
     */
    private function payloadHasDraft(ContentKind $kind, DraftPayload $payload): bool
    {
        if ($kind === ContentKind::Page) {
            return is_array($payload->slots) && $payload->slots !== [];
        }

        return is_string($payload->body) && trim($payload->body) !== '';
    }

    /**
     * Persist the failure marker on the row (status untouched): the human one-liner
     * on `draft_error` (the queue indicator reads it), the structured cause on
     * `draft_failure`. A later successful draft rebuilds `meta` wholesale, clearing
     * both.
     */
    private function mark(Content $content, DraftFailure $failure): void
    {
        $content->recordDraftFailure($failure);
    }
}
