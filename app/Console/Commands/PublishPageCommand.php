<?php

namespace App\Console\Commands;

use App\Enums\ContentSource;
use App\Models\Content;
use App\Publishing\PublishContentService;
use Illuminate\Console\Command;

/**
 * Publish (or re-publish) a single page to its WordPress instance, optionally with
 * the PLACEHOLDER content source — so the operator can push a length-representative
 * placeholder page to staging and evaluate the real composed skeleton, then push the
 * real content. Same composer/surface/brand; only slot content differs.
 *
 *   launchpad:publish-page {content} [--placeholder]
 */
class PublishPageCommand extends Command
{
    protected $signature = 'launchpad:publish-page {content : the Content id}
        {--placeholder : push length-representative placeholder content instead of the generated content}';

    protected $description = 'Publish a page to WordPress — with --placeholder for the faithful design preview.';

    public function handle(PublishContentService $service): int
    {
        $content = Content::query()->find($this->argument('content'));
        if ($content === null) {
            $this->error('Content not found.');

            return self::FAILURE;
        }

        $source = $this->option('placeholder') ? ContentSource::Placeholder : ContentSource::Generated;
        $result = $service->publish($content, null, $source);

        $tag = $source === ContentSource::Placeholder ? ' [placeholder]' : '';

        if ($result->isPublished()) {
            $this->info("Published '{$content->title}'{$tag} → wp #{$result->wpPostId}.");

            return self::SUCCESS;
        }
        if ($result->wasSkipped()) {
            $this->warn("Skipped '{$content->title}': {$result->message}");

            return self::SUCCESS;
        }

        $this->error("Could not publish '{$content->title}': {$result->message}");

        return self::FAILURE;
    }
}
