<?php

namespace App\Console\Commands;

use App\ContentEngine\Generation\PostGenerator;
use App\Models\Content;
use Illuminate\Console\Command;

class GeneratePostCommand extends Command
{
    protected $signature = 'launchpad:generate-post {content : The routed candidate Content id} {--market= : Market id for local injection}';

    protected $description = 'Generate a blog post from a routed candidate: draft (Sonnet) + image (fal) → review queue.';

    public function handle(PostGenerator $generator): int
    {
        $candidate = Content::query()->find($this->argument('content'));

        if ($candidate === null) {
            $this->error('Candidate not found.');

            return self::FAILURE;
        }

        $result = $generator->generate($candidate, $this->option('market'));
        $content = $result->content;

        $this->info(sprintf(
            "Generated '%s' → %s (silo %s).",
            $content->title,
            $content->status->value,
            $content->silo_id ?? '—',
        ));

        return self::SUCCESS;
    }
}
