<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Publishing\PostPublisher;
use Illuminate\Console\Command;

class PublishContentCommand extends Command
{
    protected $signature = 'launchpad:publish-content {content : The Content id to publish}';

    protected $description = 'Publish a single post to its connected WordPress instance (per-post analog of launch-site).';

    public function handle(PostPublisher $publisher): int
    {
        $content = Content::query()->find($this->argument('content'));

        if ($content === null) {
            $this->error('Content not found.');

            return self::FAILURE;
        }

        $result = $publisher->publish($content);

        if ($result->isPublished()) {
            $this->info("Published '{$content->title}' → wp #{$result->wpPostId}.");

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
