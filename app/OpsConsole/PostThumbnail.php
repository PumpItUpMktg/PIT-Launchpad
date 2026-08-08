<?php

namespace App\OpsConsole;

use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Operate\BlogBoard;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The post's card thumbnail — the R2 URL of its first succeeded {@see RenderJob} render (mirrors
 * {@see BlogBoard::thumbnail()}). Null when nothing has rendered yet. Assumes `renderJobs`
 * is already eager-loaded so a board of cards is one query.
 */
final class PostThumbnail
{
    public static function for(Content $post): ?string
    {
        $job = $post->renderJobs->first(
            fn (RenderJob $j): bool => $j->status === RenderStatus::Succeeded && $j->r2_key !== null,
        );
        if (! $job instanceof RenderJob) {
            return null;
        }

        try {
            return Storage::disk(TenantStorage::DISK)->url((string) $job->r2_key);
        } catch (Throwable) {
            return null;
        }
    }
}
