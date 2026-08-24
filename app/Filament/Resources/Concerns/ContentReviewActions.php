<?php

namespace App\Filament\Resources\Concerns;

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ReviewFlag;
use App\Filament\Resources\AiContentResource;
use App\Filament\Resources\ContentReviewResource;
use App\Jobs\GeneratePost;
use App\Models\Content;
use App\Publishing\PostPublisher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The review row-actions + edit form shared by every content-review surface — the §6c blog review queue
 * ({@see ContentReviewResource}) and the AI-search review page
 * ({@see AiContentResource}). Single-sourced so a change to how a draft is
 * generated / approved / published / rejected / locked lands identically on both. All logic delegates to
 * the testable {@see ReviewActions} / {@see PostPublisher} services; these builders are the thin surface.
 */
trait ContentReviewActions
{
    /**
     * The shared edit form — kit slots / body / SEO in place (persisted via ReviewActions::saveEdits by
     * the Edit page so SEO merges into meta without clobbering image specs).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Draft')->schema([
                KeyValue::make('slot_payload')->label('Kit slots')->visible(fn (?Content $record) => $record?->slot_payload !== null),
                Textarea::make('body')->rows(12)->visible(fn (?Content $record) => $record?->body !== null),
            ]),
            Section::make('SEO')->schema([
                TextInput::make('seo_title')->label('Title')->dehydrated(),
                Textarea::make('seo_meta')->label('Meta description')->rows(2)->dehydrated(),
                TextInput::make('slug'),
            ]),
        ]);
    }

    /**
     * Draft a candidate/borderline row that landed undrafted. Post lane only — a page must not be
     * re-kinded to a post. The expensive Sonnet+fal step runs on the worker, not this request.
     */
    protected static function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generate post')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn (Content $record): bool => $record->kind === ContentKind::Post && ! $record->hasDraft() && ! $record->isGenerating())
            ->requiresConfirmation()
            ->modalDescription('Queues the draft (Sonnet) + image render (fal) on the worker — the expensive step runs in the background, not in this request. The row shows "Generating" until the draft is ready.')
            ->action(function (Content $record): void {
                GeneratePost::enqueue($record, actorId: Auth::id());

                Notification::make()->success()
                    ->title('Queued — generating on the worker')
                    ->body("'{$record->title}' is being drafted; the row will update when it's ready.")->send();
            });
    }

    protected static function approveAction(): Action
    {
        return Action::make('approve')
            ->color('success')
            ->requiresConfirmation()
            ->action(function (Content $record): void {
                $result = app(ReviewActions::class)->approve($record, Auth::id());

                if ($result->isBlocked()) {
                    Notification::make()->danger()
                        ->title('Cannot approve')->body($result->blockedReason)->send();

                    return;
                }

                $notification = Notification::make()->success()->title('Approved — ready to publish');
                if ($result->warnings !== []) {
                    $notification->body(implode(' ', $result->warnings));
                }
                $notification->send();
            });
    }

    /**
     * Per-post publish — push this reviewed post straight to WordPress now. Gated on a verified,
     * non-compromised connection; reuses the proven publish path, so it honors {skipped:true} and is
     * idempotent on re-publish.
     */
    protected static function publishNowAction(): Action
    {
        return Action::make('publish_now')
            ->label('Publish now')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Content $record): bool => $record->hasDraft())
            ->requiresConfirmation()
            ->modalDescription('Renders images and pushes this post to WordPress now (keyed by content_id — safe to re-run; a page edited in WordPress is skipped, not overwritten).')
            ->action(function (Content $record): void {
                $result = app(PostPublisher::class)->publish($record, Auth::id());

                if ($result->isPublished()) {
                    Notification::make()->success()
                        ->title('Published to WordPress')->body("wp #{$result->wpPostId}")->send();

                    return;
                }

                if ($result->wasSkipped()) {
                    Notification::make()->warning()->title('Skipped')->body($result->message)->send();

                    return;
                }

                Notification::make()->danger()->title('Publish failed')->body($result->message)->send();
            });
    }

    protected static function rejectAction(): Action
    {
        return Action::make('reject')
            ->color('danger')
            ->schema([Textarea::make('reason')->required()])
            ->action(function (Content $record, array $data): void {
                app(ReviewActions::class)->reject($record, (string) $data['reason']);
                Notification::make()->success()->title('Rejected')->send();
            });
    }

    protected static function lockAction(): Action
    {
        return Action::make('lock')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Content $record) => ! $record->locked)
            ->action(function (Content $record): void {
                app(ReviewActions::class)->lock($record);
                Notification::make()->success()->title('Locked')->send();
            });
    }

    protected static function bulkApproveAction(): BulkAction
    {
        return BulkAction::make('bulkApprove')
            ->label('Approve selected')
            ->color('success')
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $results = app(ReviewActions::class)->bulkApprove($records, Auth::id());
                $blocked = count(array_filter($results, fn ($r) => $r->isBlocked()));
                $approved = count($results) - $blocked;

                Notification::make()->success()
                    ->title("Approved {$approved}, blocked {$blocked}")
                    ->send();
            });
    }

    /**
     * The drafted-vs-undrafted indicator for a queue row.
     */
    protected static function draftState(Content $record): string
    {
        return match ($record->generationState()) {
            'drafted' => 'Drafted',
            'generating' => 'Generating',
            'failed' => 'Draft failed',
            default => 'Awaiting draft',
        };
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return array<string, string>
     */
    protected static function enumOptions(array $cases): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = ucwords(str_replace('_', ' ', (string) $case->value));
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function flagOptions(): array
    {
        $options = [];
        foreach (ReviewFlag::cases() as $flag) {
            $options[$flag->value] = $flag->label();
        }

        return $options;
    }
}
