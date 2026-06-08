<?php

namespace App\Filament\Resources;

use App\ContentEngine\Review\AlertFlags;
use App\ContentEngine\Review\ReviewActions;
use App\ContentEngine\Review\ReviewQueue;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\ReviewFlag;
use App\Enums\UserRole;
use App\Filament\Resources\ContentReviewResource\Pages\EditContentReview;
use App\Filament\Resources\ContentReviewResource\Pages\ListContentReviews;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The §6c operator review queue: lists `needs_review` (and bounced-back) drafts
 * flagged-first, filterable per tenant/silo/kind/trigger/alert, with the
 * approve→publish wiring that closes the pipeline. A thin Filament surface over
 * the testable ReviewActions / AlertFlags / ReviewQueue services. Operator-only.
 */
class ContentReviewResource extends Resource
{
    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?string $modelLabel = 'draft';

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('status', ReviewQueue::statusValues())
            ->orderByRaw(ReviewQueue::priorityOrder())
            ->orderBy('created_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(40)->wrap(),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('silo.name')->label('Silo')->placeholder('—'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('draft_trigger')->label('Lane')->badge()->placeholder('—'),
                TextColumn::make('flags')
                    ->label('Flags')
                    ->badge()
                    ->state(fn (Content $record): array => array_map(
                        fn (ReviewFlag $flag) => $flag->label(),
                        AlertFlags::for($record),
                    )),
                TextColumn::make('created_at')->label('Age')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('silo_id')->label('Silo')->relationship('silo', 'name'),
                SelectFilter::make('kind')->options(self::enumOptions(ContentKind::cases())),
                SelectFilter::make('draft_trigger')->label('Lane')->options(self::enumOptions(DraftTrigger::cases())),
                SelectFilter::make('flag')
                    ->label('Alert')
                    ->options(self::flagOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        $flag = is_string($value) ? ReviewFlag::tryFrom($value) : null;

                        return $flag !== null ? AlertFlags::filter($query, $flag) : $query;
                    }),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
                self::lockAction(),
            ])
            ->bulkActions([
                self::bulkApproveAction(),
            ]);
    }

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

    private static function approveAction(): Action
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

                $notification = Notification::make()->success()->title('Approved — publish enqueued');
                if ($result->warnings !== []) {
                    $notification->body(implode(' ', $result->warnings));
                }
                $notification->send();
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->color('danger')
            ->schema([Textarea::make('reason')->required()])
            ->action(function (Content $record, array $data): void {
                app(ReviewActions::class)->reject($record, (string) $data['reason']);
                Notification::make()->success()->title('Rejected')->send();
            });
    }

    private static function lockAction(): Action
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

    private static function bulkApproveAction(): BulkAction
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
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListContentReviews::route('/'),
            'edit' => EditContentReview::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
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
    private static function flagOptions(): array
    {
        $options = [];
        foreach (ReviewFlag::cases() as $flag) {
            $options[$flag->value] = $flag->label();
        }

        return $options;
    }
}
