<?php

namespace App\Filament\Resources;

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ReviewCaptureResource\Pages\EditReview;
use App\Filament\Resources\ReviewCaptureResource\Pages\ListReviews;
use App\Models\Location;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Reviews\Approval\ReviewApprovalActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The operator review queue (Review Capture §9) — a thin Filament surface over {@see ReviewApprovalActions}.
 * Cross-tenant (needs-first), stock components, no per-row I/O: filter by status / tenant / source / rating /
 * needs-location, then approve (publishes), reject, edit body, reassign location (required to clear
 * needs_location), adjust service tags, bulk-approve, or unpublish. Operator-only.
 */
class ReviewCaptureResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Reviews';

    protected static string|\UnitEnum|null $navigationGroup = 'Reviews';

    protected static ?string $modelLabel = 'review';

    /** Menu-map bookkeeping: this new surface's final placement is a pending decision — inventories as unaddressed. */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function getEloquentQuery(): Builder
    {
        // Tenant-locked: SiteScope constrains this to the locked tenant. This carries imported reviews, so a
        // cross-tenant row here risks a wrong-tenant approval publishing a customer's words on another
        // company's site — the shape-D scope-drop is removed (tenant-lock remediation).
        return parent::getEloquentQuery()
            ->with(['location', 'site'])
            ->withCount('services')
            // Needs-location first, then pending, then the rest; newest within each.
            ->orderByRaw('CASE WHEN needs_location THEN 0 WHEN status = ? THEN 1 ELSE 2 END', [ReviewStatus::Pending->value])
            ->orderByDesc('reviewed_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rating')->badge()->formatStateUsing(fn (int $state): string => str_repeat('★', $state))
                    ->color(fn (int $state): string => $state >= 4 ? 'success' : ($state === 3 ? 'warning' : 'danger')),
                TextColumn::make('status')->badge()
                    ->color(fn (ReviewStatus $state): string => match ($state) {
                        ReviewStatus::Published => 'success',
                        ReviewStatus::Approved => 'info',
                        ReviewStatus::Rejected => 'danger',
                        ReviewStatus::Pending => 'gray',
                    }),
                TextColumn::make('source')->badge()->color(fn (ReviewSource $state): string => $state === ReviewSource::Imported ? 'gray' : 'primary')
                    ->formatStateUsing(fn (ReviewSource $state, Review $record): string => $state === ReviewSource::Imported ? 'Imported'.($record->import_source ? ' ('.$record->import_source.')' : '') : 'First-party'),
                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('location.name')->label('Location')
                    ->placeholder('⚠ needs location')->color(fn (Review $record): string => $record->needs_location ? 'danger' : 'gray'),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('body')->limit(60)->wrap(),
                TextColumn::make('reviewed_at')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ReviewStatus::cases())->mapWithKeys(fn (ReviewStatus $s): array => [$s->value => $s->label()])->all()),
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('source')->options(collect(ReviewSource::cases())->mapWithKeys(fn (ReviewSource $s): array => [$s->value => $s->label()])->all()),
                SelectFilter::make('rating')->options([1 => '1★', 2 => '2★', 3 => '3★', 4 => '4★', 5 => '5★']),
                TernaryFilter::make('needs_location')->label('Needs location'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->hidden(fn (Review $record): bool => $record->status === ReviewStatus::Published)
                    ->action(function (Review $record): void {
                        app(ReviewApprovalActions::class)->approve($record, (string) Auth::id())
                            ? Notification::make()->success()->title('Approved & published')->send()
                            : Notification::make()->warning()->title('Assign a location first')->body('Reassign this review to a location before approving.')->send();
                    }),
                Action::make('unpublish')
                    ->icon('heroicon-o-eye-slash')->color('gray')->requiresConfirmation()
                    ->visible(fn (Review $record): bool => $record->status === ReviewStatus::Published)
                    ->action(function (Review $record): void {
                        app(ReviewApprovalActions::class)->unpublish($record, (string) Auth::id());
                        Notification::make()->success()->title('Unpublished')->send();
                    }),
                EditAction::make(),
                Action::make('reject')
                    ->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->hidden(fn (Review $record): bool => $record->status === ReviewStatus::Rejected)
                    ->action(function (Review $record): void {
                        app(ReviewApprovalActions::class)->reject($record);
                        Notification::make()->success()->title('Rejected')->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('bulkApprove')
                    ->label('Approve selected')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $actions = app(ReviewApprovalActions::class);
                        $approved = 0;
                        foreach ($records as $record) {
                            if ($record instanceof Review && $actions->approve($record, (string) Auth::id())) {
                                $approved++;
                            }
                        }
                        Notification::make()->success()->title("Approved {$approved} review(s)")
                            ->body($approved < $records->count() ? 'Skipped reviews with no assigned location.' : null)->send();
                    }),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->required()->rows(4),
            Select::make('location_id')->label('Location')->searchable()
                ->helperText('Reassign the owning location — required to clear a "needs location" review.')
                ->options(fn (?Review $record): array => $record !== null
                    ? Location::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $record->site_id)->pluck('name', 'id')->all()
                    : []),
            Select::make('services')->label('Service tags')->multiple()->relationship('services', 'name')
                ->maxItems(Review::MAX_SERVICES)
                ->options(fn (?Review $record): array => $record !== null
                    ? Service::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $record->site_id)->pluck('name', 'id')->all()
                    : []),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
            'edit' => EditReview::route('/{record}/edit'),
        ];
    }
}
